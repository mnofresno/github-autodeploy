#!/usr/bin/env bash
set -uo pipefail

EVIDENCE_FILE="${GITHUB_WORKSPACE:-$PWD}/deployment-evidence.jsonl"
: > "$EVIDENCE_FILE"
MAX_WAIT_SECONDS=2700
POLL_INTERVAL_SECONDS=5
CAPABILITY='github-actions-run-auth-v1'

require() {
  command -v "$1" >/dev/null 2>&1 || { echo "Required command unavailable: $1" >&2; exit 69; }
}
require curl
require jq

for name in AUTODEPLOY_URL KEY_FILE_FOR_DEPLOY GITHUB_ACTIONS_TOKEN GITHUB_REPOSITORY GITHUB_RUN_ID REPOSITORY COMMIT_SHA COMMIT_AUTHOR; do
  [[ -n "${!name:-}" ]] || { echo "Required deployment input is empty: $name" >&2; exit 64; }
done

OWNER="${GITHUB_REPOSITORY%%/*}"
[[ "$GITHUB_REPOSITORY" == "$OWNER/$REPOSITORY" ]] || { echo 'GitHub repository does not match deployment repository' >&2; exit 64; }
[[ "$OWNER" =~ ^[A-Za-z0-9_.-]+$ ]] || exit 64
[[ "$REPOSITORY" =~ ^[A-Za-z0-9_.-]+$ ]] || exit 64
[[ "$GITHUB_RUN_ID" =~ ^[0-9]+$ ]] || exit 64
[[ "$COMMIT_SHA" =~ ^[0-9a-fA-F]{40}$ ]] || exit 64

urlencode() { jq -nr --arg value "$1" '$value|@uri'; }
safe() { LC_ALL=C printf '%s' "$1" | LC_ALL=C tr -cd 'A-Za-z0-9._/:-' | cut -c1-80; }

write_evidence() {
  jq -nc \
    --arg repository "$REPOSITORY" \
    --arg commit_sha "$COMMIT_SHA" \
    --arg status "$1" \
    --arg run_id "${2:-}" \
    --arg phase "$(safe "${3:-}")" \
    --arg step_id "${4:-}" \
    --arg exit_code "${5:-}" \
    --arg error_code "$(safe "${6:-}")" \
    '{repository:$repository,commit_sha:$commit_sha,status:$status,run_id:(if $run_id=="" then null else $run_id end),error_code:(if $error_code=="" then null else $error_code end),failed_step:(if ($phase=="" and $step_id=="" and $exit_code=="") then null else {phase:(if $phase=="" then null else $phase end),step_id:(if $step_id=="" then null else ($step_id|tonumber?//$step_id) end),exit_code:(if $exit_code=="" then null else ($exit_code|tonumber?//$exit_code) end)} end)}' >> "$EVIDENCE_FILE"
}

controller_has_capability() {
  local host="$1" file code rc
  file="$(mktemp)"
  code="$(curl --silent --show-error --connect-timeout 10 --max-time 30 --output "$file" --write-out '%{http_code}' "https://$host/controller-capabilities" 2>/dev/null)"
  rc=$?
  if (( rc == 0 )) && [[ "$code" == 200 ]] && jq -e --arg c "$CAPABILITY" '.capabilities|arrays|index($c)!=null' "$file" >/dev/null 2>&1; then
    rm -f "$file"
    return 0
  fi
  rm -f "$file"
  return 1
}

ensure_controller() {
  local host="$1" code rc
  controller_has_capability "$host" && return 0
  code="$(curl --silent --show-error --connect-timeout 20 --max-time 300 --output /dev/null --write-out '%{http_code}' "https://$host/self-update" 2>/dev/null)"
  rc=$?
  (( rc == 0 )) && [[ "$code" =~ ^2[0-9][0-9]$ ]] || return 1
  for _ in $(seq 1 15); do
    sleep 2
    controller_has_capability "$host" && return 0
  done
  return 1
}

extra_params=''
if [[ -n "${ENV_VARS:-}" ]]; then
  while IFS= read -r pair; do
    [[ -n "$pair" ]] || continue
    [[ "$pair" == *=* ]] || exit 64
    key="${pair%%=*}"
    value="${pair#*=}"
    [[ "$key" =~ ^[A-Za-z_][A-Za-z0-9_-]*$ ]] || exit 64
    extra_params+="&env_$(urlencode "$key")=$(urlencode "$value")"
  done < <(printf '%s' "$ENV_VARS" | tr '|' '\n')
fi

IFS=',' read -r -a hosts <<< "$AUTODEPLOY_URL"
all_success=true
for raw_host in "${hosts[@]}"; do
  host="$(printf '%s' "$raw_host" | xargs)"
  [[ -n "$host" && "$host" != *[!A-Za-z0-9._:-]* ]] || { write_evidence INVALID_INSTANCE; all_success=false; continue; }

  if ! ensure_controller "$host"; then
    write_evidence CONTROLLER_UPDATE_FAILED '' controller '' 70 controller_update_failed
    all_success=false
    continue
  fi

  response="$(mktemp)"
  payload="$(jq -nc --arg key "$KEY_FILE_FOR_DEPLOY" --arg sha "$COMMIT_SHA" --arg author "$COMMIT_AUTHOR" '{key:$key,run_in_background:true,commit:{sha:$sha,author:$author}}')"
  url="https://$host?repo=$(urlencode "$REPOSITORY")&key=$(urlencode "$KEY_FILE_FOR_DEPLOY")&create_repo_if_not_exists=true&clone_path=$(urlencode "$OWNER,$REPOSITORY")$extra_params"
  code="$(curl --silent --show-error --connect-timeout 30 --max-time 60 \
    --request POST \
    --header 'Content-Type: application/json' \
    --header "X-GitHub-Actions-Token: $GITHUB_ACTIONS_TOKEN" \
    --header "X-GitHub-Repository: $GITHUB_REPOSITORY" \
    --header "X-Autodeploy-Repository: $REPOSITORY" \
    --header "X-GitHub-Run-Id: $GITHUB_RUN_ID" \
    --header "X-GitHub-Sha: $COMMIT_SHA" \
    --data "$payload" --output "$response" --write-out '%{http_code}' "$url" 2>/dev/null)"
  rc=$?
  if (( rc != 0 )) || [[ "$code" != 201 ]] || ! jq empty "$response" >/dev/null 2>&1; then
    write_evidence REQUEST_REJECTED '' request '' "${code:-$rc}" request_rejected
    rm -f "$response"
    all_success=false
    continue
  fi

  run_id="$(jq -r '.run_id//empty' "$response")"
  rm -f "$response"
  [[ "$run_id" =~ ^[A-Za-z0-9._:-]+$ ]] || { write_evidence MISSING_RUN_ID; all_success=false; continue; }

  started="$(date +%s)"
  while true; do
    elapsed=$(( $(date +%s) - started ))
    if (( elapsed >= MAX_WAIT_SECONDS )); then
      write_evidence POLL_TIMEOUT "$run_id" poll '' 124 poll_timeout
      all_success=false
      break
    fi

    status_file="$(mktemp)"
    poll_code="$(curl --silent --show-error --connect-timeout 10 --max-time 30 --output "$status_file" --write-out '%{http_code}' "https://$host?deployment_status=true&previous_run_id=$(urlencode "$run_id")" 2>/dev/null)"
    poll_rc=$?
    if (( poll_rc != 0 )) || [[ "$poll_code" != 200 ]] || ! jq empty "$status_file" >/dev/null 2>&1; then
      rm -f "$status_file"
      sleep "$POLL_INTERVAL_SECONDS"
      continue
    fi

    status="$(jq -r '.status//"UNKNOWN"' "$status_file")"
    case "$status" in
      SUCCESS)
        write_evidence SUCCESS "$run_id"
        rm -f "$status_file"
        break
        ;;
      FAILED)
        phase="$(jq -r '.failed_step.phase//empty' "$status_file")"
        step="$(jq -r '.failed_step.step_id//empty' "$status_file")"
        exit_code="$(jq -r '.failed_step.exit_code//empty' "$status_file")"
        error_code="$(jq -r '.error_code//"unclassified_server_failure"' "$status_file")"
        write_evidence FAILED "$run_id" "$phase" "$step" "$exit_code" "$error_code"
        rm -f "$status_file"
        all_success=false
        break
        ;;
      *)
        rm -f "$status_file"
        sleep "$POLL_INTERVAL_SECONDS"
        ;;
    esac
  done
done

$all_success || { echo 'One or more deployments failed; see sanitized evidence' >&2; exit 1; }
echo 'All deployments completed and were verified by the server'
