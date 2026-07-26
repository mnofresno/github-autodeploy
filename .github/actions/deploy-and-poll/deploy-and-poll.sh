#!/usr/bin/env bash
set -uo pipefail

EVIDENCE_FILE="${GITHUB_WORKSPACE:-$PWD}/deployment-evidence.jsonl"
: > "$EVIDENCE_FILE"

MAX_WAIT_SECONDS=2700
POLL_INTERVAL_SECONDS=5
PROGRESS_INTERVAL_SECONDS=30

require_command() {
  if ! command -v "$1" >/dev/null 2>&1; then
    echo "Required command is unavailable: $1" >&2
    exit 69
  fi
}

require_command curl
require_command jq

for required in AUTODEPLOY_URL KEY_FILE_FOR_DEPLOY REPOSITORY COMMIT_SHA COMMIT_AUTHOR; do
  if [[ -z "${!required:-}" ]]; then
    echo "Required deployment input is empty: $required" >&2
    exit 64
  fi
done

if [[ ! "$COMMIT_SHA" =~ ^[0-9a-fA-F]{40}$ ]]; then
  echo "The requested commit SHA is not a full 40-character Git SHA" >&2
  exit 64
fi

urlencode() {
  jq -nr --arg value "$1" '$value | @uri'
}

safe_token() {
  printf '%s' "$1" | tr -cd 'A-Za-z0-9._/' | cut -c1-80
}

classify_error_message() {
  local normalized
  normalized="$(printf '%s' "$1" | tr '[:upper:]' '[:lower:]')"
  case "$normalized" in
    *"repo"*"not"*"exist"*|*"repository"*"not"*"found"*) printf 'repository_not_found' ;;
    *"invalid"*"deploy"*|*"yaml"*"parse"*|*"configuration"*"invalid"*) printf 'invalid_deploy_config' ;;
    *"permission denied"*|*"unable to unlink"*|*"operation not permitted"*) printf 'permission_denied' ;;
    *"authentication"*|*"unauthorized"*|*"access denied"*|*"could not read from remote"*) printf 'authentication_failed' ;;
    *"not a git repository"*|*"git"*"failed"*|*"fetch"*"failed"*) printf 'checkout_failed' ;;
    *"timeout"*|*"timed out"*) printf 'server_timeout' ;;
    *"missing"*"repo"*|*"missing"*"key"*|*"bad request"*) printf 'bad_request' ;;
    '') printf 'server_error_unclassified' ;;
    *) printf 'server_error_unclassified' ;;
  esac
}

write_evidence() {
  local instance_index="$1"
  local instance_count="$2"
  local status="$3"
  local run_id="${4:-}"
  local phase="${5:-}"
  local step_id="${6:-}"
  local exit_code="${7:-}"
  local started_at="${8:-}"
  local completed_at="${9:-}"
  local error_code="${10:-}"

  jq -nc \
    --arg repository "$REPOSITORY" \
    --arg commit_sha "$COMMIT_SHA" \
    --arg status "$status" \
    --arg run_id "$run_id" \
    --arg phase "$(safe_token "$phase")" \
    --arg step_id "$step_id" \
    --arg exit_code "$exit_code" \
    --arg started_at "$started_at" \
    --arg completed_at "$completed_at" \
    --arg error_code "$(safe_token "$error_code")" \
    --argjson instance_index "$instance_index" \
    --argjson instance_count "$instance_count" \
    '{
      repository: $repository,
      commit_sha: $commit_sha,
      instance_index: $instance_index,
      instance_count: $instance_count,
      status: $status,
      run_id: (if $run_id == "" then null else $run_id end),
      started_at: (if $started_at == "" then null else $started_at end),
      completed_at: (if $completed_at == "" then null else $completed_at end),
      error_code: (if $error_code == "" then null else $error_code end),
      failed_step: (if ($phase == "" and $step_id == "" and $exit_code == "") then null else {
        phase: (if $phase == "" then null else $phase end),
        step_id: (if $step_id == "" then null else ($step_id | tonumber? // $step_id) end),
        exit_code: (if $exit_code == "" then null else ($exit_code | tonumber? // $exit_code) end)
      } end)
    }' >> "$EVIDENCE_FILE"
}

extra_params=""
if [[ -n "${ENV_VARS:-}" ]]; then
  while IFS= read -r pair; do
    [[ -n "$pair" ]] || continue
    if [[ "$pair" != *=* ]]; then
      echo "Invalid deployment variable; expected KEY=VALUE" >&2
      exit 64
    fi
    key="${pair%%=*}"
    value="${pair#*=}"
    if [[ ! "$key" =~ ^[A-Za-z_][A-Za-z0-9_-]*$ ]]; then
      echo "Invalid deployment variable name" >&2
      exit 64
    fi
    extra_params+="&env_$(urlencode "$key")=$(urlencode "$value")"
  done < <(printf '%s' "$ENV_VARS" | tr '|' '\n')
fi

IFS=',' read -r -a instances <<< "$AUTODEPLOY_URL"
instance_count=${#instances[@]}
if (( instance_count == 0 )); then
  echo "No autodeploy instances were supplied" >&2
  exit 64
fi

echo "Starting verified deployment"
echo "Repository: $REPOSITORY"
echo "Commit: $COMMIT_SHA"
echo "Instances: $instance_count"

all_success=true

for index in "${!instances[@]}"; do
  instance="$(printf '%s' "${instances[$index]}" | xargs)"
  instance_number=$((index + 1))
  if [[ -z "$instance" || "$instance" == *[!A-Za-z0-9._:-]* ]]; then
    echo "Instance ${instance_number}: invalid server hostname" >&2
    write_evidence "$instance_number" "$instance_count" "INVALID_INSTANCE" "" "" "" "" "" "" "invalid_instance"
    all_success=false
    continue
  fi

  echo "Instance ${instance_number}/${instance_count}: requesting deployment"

  response_file="$(mktemp)"
  error_file="$(mktemp)"
  payload="$(jq -nc \
    --arg key "$KEY_FILE_FOR_DEPLOY" \
    --arg sha "$COMMIT_SHA" \
    --arg author "$COMMIT_AUTHOR" \
    '{key:$key,run_in_background:true,commit:{sha:$sha,author:$author}}')"
  deployment_url="https://${instance}?repo=$(urlencode "$REPOSITORY")&key=$(urlencode "$KEY_FILE_FOR_DEPLOY")${extra_params}"

  http_code="$(curl \
    --silent \
    --show-error \
    --connect-timeout 30 \
    --max-time 60 \
    --request POST \
    --header 'Content-Type: application/json' \
    --data "$payload" \
    --output "$response_file" \
    --write-out '%{http_code}' \
    "$deployment_url" 2>"$error_file")"
  curl_exit=$?

  if (( curl_exit != 0 )); then
    echo "Instance ${instance_number}: deployment request transport failure (curl exit ${curl_exit})" >&2
    write_evidence "$instance_number" "$instance_count" "REQUEST_TRANSPORT_FAILURE" "" "request" "" "$curl_exit" "" "" "request_transport_failure"
    rm -f "$response_file" "$error_file"
    all_success=false
    continue
  fi

  if [[ "$http_code" != "201" ]]; then
    echo "Instance ${instance_number}: deployment request rejected (HTTP ${http_code})" >&2
    write_evidence "$instance_number" "$instance_count" "REQUEST_REJECTED" "" "request" "" "$http_code" "" "" "request_rejected"
    rm -f "$response_file" "$error_file"
    all_success=false
    continue
  fi

  if ! jq empty "$response_file" >/dev/null 2>&1; then
    echo "Instance ${instance_number}: deployment server returned invalid JSON" >&2
    write_evidence "$instance_number" "$instance_count" "INVALID_START_RESPONSE" "" "request" "" "" "" "" "invalid_start_response"
    rm -f "$response_file" "$error_file"
    all_success=false
    continue
  fi

  run_id="$(jq -r '.run_id // empty' "$response_file")"
  rm -f "$response_file" "$error_file"
  if [[ -z "$run_id" || ! "$run_id" =~ ^[A-Za-z0-9._:-]+$ ]]; then
    echo "Instance ${instance_number}: deployment server did not return a valid run ID" >&2
    write_evidence "$instance_number" "$instance_count" "MISSING_RUN_ID" "" "request" "" "" "" "" "missing_run_id"
    all_success=false
    continue
  fi

  echo "Instance ${instance_number}: server run accepted"
  start_epoch="$(date +%s)"
  last_progress_epoch=0

  while true; do
    now_epoch="$(date +%s)"
    elapsed=$((now_epoch - start_epoch))
    if (( elapsed >= MAX_WAIT_SECONDS )); then
      echo "Instance ${instance_number}: deployment verification timed out" >&2
      write_evidence "$instance_number" "$instance_count" "POLL_TIMEOUT" "$run_id" "poll" "" "124" "" "" "poll_timeout"
      all_success=false
      break
    fi

    status_file="$(mktemp)"
    poll_http="$(curl \
      --silent \
      --show-error \
      --connect-timeout 10 \
      --max-time 30 \
      --output "$status_file" \
      --write-out '%{http_code}' \
      "https://${instance}?deployment_status=true&previous_run_id=$(urlencode "$run_id")" 2>/dev/null)"
    poll_exit=$?

    if (( poll_exit != 0 )) || [[ "$poll_http" != "200" ]] || ! jq empty "$status_file" >/dev/null 2>&1; then
      rm -f "$status_file"
      if (( now_epoch - last_progress_epoch >= PROGRESS_INTERVAL_SECONDS )); then
        echo "Instance ${instance_number}: waiting for a valid server status (${elapsed}s)"
        last_progress_epoch=$now_epoch
      fi
      sleep "$POLL_INTERVAL_SECONDS"
      continue
    fi

    response_type="$(jq -r 'type' "$status_file" 2>/dev/null || printf 'invalid')"
    if [[ "$response_type" == "array" ]]; then
      normalized_file="$(mktemp)"
      jq '.[0] // {}' "$status_file" > "$normalized_file"
      mv "$normalized_file" "$status_file"
    fi

    status="$(jq -r '.status // "UNKNOWN"' "$status_file")"
    phase="$(jq -r '.current_phase // empty' "$status_file")"
    current_step="$(jq -r '.current_step // empty' "$status_file")"

    case "$status" in
      RUNNING)
        if (( now_epoch - last_progress_epoch >= PROGRESS_INTERVAL_SECONDS )); then
          safe_phase="$(safe_token "$phase")"
          [[ -n "$safe_phase" ]] || safe_phase="running"
          if [[ "$current_step" =~ ^[0-9]+$ ]]; then
            echo "Instance ${instance_number}: ${safe_phase}, step $((current_step + 1)) (${elapsed}s)"
          else
            echo "Instance ${instance_number}: ${safe_phase} (${elapsed}s)"
          fi
          last_progress_epoch=$now_epoch
        fi
        rm -f "$status_file"
        sleep "$POLL_INTERVAL_SECONDS"
        ;;
      SUCCESS)
        started_at="$(jq -r '.started_at // empty' "$status_file")"
        completed_at="$(jq -r '.completed_at // empty' "$status_file")"
        write_evidence "$instance_number" "$instance_count" "SUCCESS" "$run_id" "" "" "" "$started_at" "$completed_at" ""
        echo "Instance ${instance_number}: deployment and server verification succeeded"
        rm -f "$status_file"
        break
        ;;
      FAILED)
        failed_phase="$(jq -r '.failed_step.phase // empty' "$status_file")"
        failed_step="$(jq -r '.failed_step.step_id // empty' "$status_file")"
        failed_exit="$(jq -r '.failed_step.exit_code // empty' "$status_file")"
        started_at="$(jq -r '.started_at // empty' "$status_file")"
        completed_at="$(jq -r '.completed_at // .failed_at // empty' "$status_file")"
        server_error_message="$(jq -r '.error_message // empty' "$status_file")"
        error_code="$(classify_error_message "$server_error_message")"
        if [[ "$failed_step" =~ ^[0-9]+$ ]] && (( failed_step >= 0 )); then
          error_code="command_failed"
        fi
        write_evidence "$instance_number" "$instance_count" "FAILED" "$run_id" "$failed_phase" "$failed_step" "$failed_exit" "$started_at" "$completed_at" "$error_code"
        echo "Instance ${instance_number}: server deployment failed (error=${error_code}, phase=$(safe_token "$failed_phase"), step=${failed_step:-unknown}, exit=${failed_exit:-unknown})" >&2
        rm -f "$status_file"
        all_success=false
        break
        ;;
      *)
        rm -f "$status_file"
        if (( now_epoch - last_progress_epoch >= PROGRESS_INTERVAL_SECONDS )); then
          echo "Instance ${instance_number}: deployment status not terminal yet (${elapsed}s)"
          last_progress_epoch=$now_epoch
        fi
        sleep "$POLL_INTERVAL_SECONDS"
        ;;
    esac
  done
done

if [[ "$all_success" == "true" ]]; then
  echo "All deployments completed and were verified"
  exit 0
fi

echo "One or more deployments failed; see the sanitized evidence artifact" >&2
exit 1
