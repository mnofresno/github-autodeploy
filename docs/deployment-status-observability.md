# Connector-verifiable deployment evidence

`reusable-verified-deploy.yml` wraps the existing asynchronous deployment workflow without changing its server-side behavior.

For every production deployment it publishes a GitHub commit status with:

- the exact commit SHA used by the deployment request;
- a service-specific status context;
- `pending`, `success`, or `failure` state;
- a link to the GitHub Actions run containing the server polling logs;
- an optional sanitized subject such as an immutable image SHA.

The status never includes deploy keys, environment variables, commands, server URLs, request payloads, user data, or command output.

A `success` status means the existing reusable deployment workflow received a final `SUCCESS` result from github-autodeploy after all repository-specific post-deploy commands and health checks completed. It does not treat the initial HTTP acceptance response as success.

Callers must grant `statuses: write`; the called workflow cannot elevate the caller token permissions.
