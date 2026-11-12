#!/usr/bin/env bash
#
# End-to-end workflow test for the Quantix Service Request API.
#
#   employee creates + submits -> manager approves -> operations assigns
#   -> assigned operations user starts + completes -> activity history
#
# Usage:
#   export DEMO_PASSWORD='...'              # demo password from README > Demo Accounts
#   bash docs/scripts/test-workflow.sh
#
# Optional environment variables (defaults are the seeded demo accounts):
#   BASE_URL            http://127.0.0.1:8000
#   EMPLOYEE_EMAIL      employee1@quantix.test
#   MANAGER_EMAIL       manager@quantix.test
#   OPERATIONS_EMAIL    operations@quantix.test   (assigns the request)
#   ASSIGNEE_EMAIL      operations2@quantix.test  (does the work)
#   EMPLOYEE_PASSWORD, MANAGER_PASSWORD, OPERATIONS_PASSWORD, ASSIGNEE_PASSWORD
#                       per-user passwords; each defaults to DEMO_PASSWORD
#
# The script creates ONE service request, checks every HTTP status code,
# stops at the first unexpected response, and logs out all tokens it created.

set -euo pipefail

BASE_URL="${BASE_URL:-http://127.0.0.1:8000}"
EMPLOYEE_EMAIL="${EMPLOYEE_EMAIL:-employee1@quantix.test}"
MANAGER_EMAIL="${MANAGER_EMAIL:-manager@quantix.test}"
OPERATIONS_EMAIL="${OPERATIONS_EMAIL:-operations@quantix.test}"
ASSIGNEE_EMAIL="${ASSIGNEE_EMAIL:-operations2@quantix.test}"

EMPLOYEE_PASSWORD="${EMPLOYEE_PASSWORD:-${DEMO_PASSWORD:-}}"
MANAGER_PASSWORD="${MANAGER_PASSWORD:-${DEMO_PASSWORD:-}}"
OPERATIONS_PASSWORD="${OPERATIONS_PASSWORD:-${DEMO_PASSWORD:-}}"
ASSIGNEE_PASSWORD="${ASSIGNEE_PASSWORD:-${DEMO_PASSWORD:-}}"

# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

fail() {
    echo "FAIL: $*" >&2
    exit 1
}

for cmd in curl php; do
    command -v "$cmd" >/dev/null 2>&1 || fail "'$cmd' is required but not installed."
done

for var in EMPLOYEE_PASSWORD MANAGER_PASSWORD OPERATIONS_PASSWORD ASSIGNEE_PASSWORD; do
    [ -n "${!var}" ] || fail "Set DEMO_PASSWORD (or $var) before running this script."
done

# json_get '<jq path>'  - reads JSON from stdin, e.g. json_get '.data.id'
if command -v jq >/dev/null 2>&1; then
    json_get() { jq -r "$1 // empty"; }
else
    # Fallback without jq: supports simple paths like .data.user.id
    json_get() {
        php -r '
            $value = json_decode(stream_get_contents(STDIN), true);
            foreach (array_filter(explode(".", $argv[1]), "strlen") as $key) {
                $value = is_array($value) && array_key_exists($key, $value) ? $value[$key] : null;
            }
            echo is_scalar($value) ? $value : "";
        ' "$1"
    }
fi

# JSON string escaping for request bodies (handles quotes in passwords).
json_string() {
    php -r 'echo json_encode($argv[1]);' "$1"
}

STEP=0
RESPONSE=""

# call <expected status> <method> <path> <token or "-"> [json body]
# Stores the response body in $RESPONSE.
call() {
    local expected="$1" method="$2" path="$3" token="$4" body="${5:-}"
    local args=(-s -X "$method" "$BASE_URL/api$path" -H "Accept: application/json" -w $'\n%{http_code}')

    [ "$token" != "-" ] && args+=(-H "Authorization: Bearer $token")
    [ -n "$body" ] && args+=(-H "Content-Type: application/json" -d "$body")

    local output status
    output=$(curl "${args[@]}") || fail "$method $path - could not connect to $BASE_URL"
    status="${output##*$'\n'}"
    RESPONSE="${output%$'\n'*}"

    if [ "$status" != "$expected" ]; then
        echo "  -> HTTP $status (expected $expected)" >&2
        echo "  -> $RESPONSE" >&2
        fail "$method $path"
    fi
}

step() {
    STEP=$((STEP + 1))
    printf '%2d. %-52s' "$STEP" "$1"
}

ok() {
    echo "OK  ${1:-}"
}

expect_equal() {
    [ "$1" = "$2" ] || fail "$3: expected '$2', got '$1'"
}

TOKENS=()

cleanup() {
    for token in "${TOKENS[@]+"${TOKENS[@]}"}"; do
        curl -s -o /dev/null -X POST "$BASE_URL/api/logout" \
            -H "Accept: application/json" \
            -H "Authorization: Bearer $token" || true
    done
}
trap cleanup EXIT

# login <email> <password> <expected role>  - prints nothing, sets LOGIN_TOKEN / LOGIN_ID
login() {
    call 200 POST /login - "{\"email\": $(json_string "$1"), \"password\": $(json_string "$2")}"
    LOGIN_TOKEN=$(echo "$RESPONSE" | json_get '.data.token')
    LOGIN_ID=$(echo "$RESPONSE" | json_get '.data.user.id')
    [ -n "$LOGIN_TOKEN" ] || fail "login $1 returned no token"
    expect_equal "$(echo "$RESPONSE" | json_get '.data.user.role')" "$3" "role of $1"
    TOKENS+=("$LOGIN_TOKEN")
}

# ---------------------------------------------------------------------------
# Workflow
# ---------------------------------------------------------------------------

echo "Quantix API workflow test against $BASE_URL"
echo

curl -s -o /dev/null "$BASE_URL/up" || fail "API is not reachable at $BASE_URL - start it with: php artisan serve"

step "Employee logs in ($EMPLOYEE_EMAIL)"
login "$EMPLOYEE_EMAIL" "$EMPLOYEE_PASSWORD" employee
EMPLOYEE_TOKEN="$LOGIN_TOKEN"
EMPLOYEE_ID="$LOGIN_ID"
ok "user #$EMPLOYEE_ID"

step "Employee creates a request"
call 201 POST /requests "$EMPLOYEE_TOKEN" \
    '{"title": "Ergonomic chair", "description": "Requesting an ergonomic chair for my workstation.", "category": "Office Equipment"}'
REQUEST_ID=$(echo "$RESPONSE" | json_get '.data.id')
[ -n "$REQUEST_ID" ] || fail "create request returned no id"
expect_equal "$(echo "$RESPONSE" | json_get '.data.status')" draft "status after create"
expect_equal "$(echo "$RESPONSE" | json_get '.data.employee_id')" "$EMPLOYEE_ID" "owner"
ok "request #$REQUEST_ID (draft)"

step "Employee submits the request"
call 200 POST "/requests/$REQUEST_ID/submit" "$EMPLOYEE_TOKEN"
expect_equal "$(echo "$RESPONSE" | json_get '.data.status')" submitted "status after submit"
ok "submitted"

step "Manager logs in ($MANAGER_EMAIL)"
login "$MANAGER_EMAIL" "$MANAGER_PASSWORD" manager
MANAGER_TOKEN="$LOGIN_TOKEN"
ok "user #$LOGIN_ID"

step "Manager approves the request"
call 200 POST "/requests/$REQUEST_ID/decision" "$MANAGER_TOKEN" \
    '{"decision": "approved", "comment": "Approved."}'
expect_equal "$(echo "$RESPONSE" | json_get '.data.status')" approved "status after approval"
ok "approved"

step "Operations logs in ($OPERATIONS_EMAIL)"
login "$OPERATIONS_EMAIL" "$OPERATIONS_PASSWORD" operations
OPERATIONS_TOKEN="$LOGIN_TOKEN"
ok "user #$LOGIN_ID"

step "Assignee logs in ($ASSIGNEE_EMAIL)"
login "$ASSIGNEE_EMAIL" "$ASSIGNEE_PASSWORD" operations
ASSIGNEE_TOKEN="$LOGIN_TOKEN"
ASSIGNEE_ID="$LOGIN_ID"
ok "user #$ASSIGNEE_ID"

step "Operations assigns the request to the assignee"
call 200 POST "/requests/$REQUEST_ID/assign" "$OPERATIONS_TOKEN" "{\"assigned_to\": $ASSIGNEE_ID}"
expect_equal "$(echo "$RESPONSE" | json_get '.data.assigned_to')" "$ASSIGNEE_ID" "assigned_to"
ok "assigned to user #$ASSIGNEE_ID"

step "Assignee starts work"
call 200 POST "/requests/$REQUEST_ID/status" "$ASSIGNEE_TOKEN" \
    '{"status": "in_progress", "comment": "Chair ordered."}'
expect_equal "$(echo "$RESPONSE" | json_get '.data.status')" in_progress "status after start"
ok "in_progress"

step "Assignee completes work"
call 200 POST "/requests/$REQUEST_ID/status" "$ASSIGNEE_TOKEN" \
    '{"status": "completed", "comment": "Chair delivered."}'
expect_equal "$(echo "$RESPONSE" | json_get '.data.status')" completed "status after completion"
[ -n "$(echo "$RESPONSE" | json_get '.data.completed_at')" ] || fail "completed_at was not set"
ok "completed"

step "Employee fetches the activity history"
call 200 GET "/requests/$REQUEST_ID/activities" "$EMPLOYEE_TOKEN"
TOTAL=$(echo "$RESPONSE" | json_get '.meta.total')
expect_equal "$TOTAL" 6 "number of activities"
ok "$TOTAL activities"

echo
echo "Activity history for request #$REQUEST_ID (oldest first):"
echo "$RESPONSE" | php -r '
    $rows = json_decode(stream_get_contents(STDIN), true)["data"];
    usort($rows, fn ($a, $b) => $a["id"] <=> $b["id"]);
    foreach ($rows as $a) {
        printf("  %-20s %-15s %-11s -> %-11s by %s (%s)\n",
            $a["created_at"], $a["action"], $a["from_status"] ?? "-", $a["to_status"],
            $a["actor"]["name"], $a["actor"]["role"]);
    }
'

echo
echo "PASSED: request #$REQUEST_ID went from draft to completed."
