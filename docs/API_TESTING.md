# API Testing with cURL

This guide lets you test the Quantix Service Request API from a terminal using only **PHP**, **cURL** and the **demo accounts** created by the database seeders. Every command below was run against the real application.

- Base URL: `http://127.0.0.1:8000` (all routes are under `/api`)
- Tools: `curl`, and [`jq`](https://jqlang.github.io/jq/) to read values from JSON responses
- Demo accounts: see [README › Demo Accounts](../README.md#demo-accounts)

For a fully automated run of the main workflow, see [`docs/scripts/test-workflow.sh`](scripts/test-workflow.sh).

---

## Contents

0. [Before you start](#0-before-you-start)
1. [Authentication](#1-authentication)
2. [Employee operations](#2-employee-operations)
3. [Manager operations](#3-manager-operations)
4. [Operations](#4-operations)
5. [Search, filtering, sorting and pagination](#5-search-filtering-sorting-and-pagination)
6. [Negative tests](#6-negative-tests)
7. [Notifications (optional)](#7-notifications-optional)
8. [Clean up](#8-clean-up)
9. [Status code reference](#9-status-code-reference)

Run the sections **in order, in the same terminal session**. Later sections reuse the shell variables (tokens and request ids) created by earlier ones.

Ids and timestamps in the example responses come from a development database and will be different on yours.

---

## 0. Before you start

Start the API and make sure the database is migrated and seeded:

```text
php artisan migrate --seed      # only once, on an empty database
php artisan serve --host=127.0.0.1 --port=8000
```

In a **second terminal**, set the shell variables used by every example:

```bash
BASE_URL="http://127.0.0.1:8000"

# Demo accounts from database/seeders/UserSeeder.php
EMPLOYEE_EMAIL="employee1@quantix.test"     # Alex Employee   (reports to John Manager)
EMPLOYEE2_EMAIL="employee2@quantix.test"    # Sarah Employee  (reports to John Manager)
MANAGER_EMAIL="manager@quantix.test"        # John Manager
OPERATIONS_EMAIL="operations@quantix.test"  # David Operations
OPERATIONS2_EMAIL="operations2@quantix.test" # Michael Operations
```

Enter the demo password once. It is read without echoing and is never written to a file:

```bash
read -rsp "Demo password: " DEMO_PASSWORD; echo
export DEMO_PASSWORD
```

> Every request sends `Accept: application/json` so Laravel always answers with JSON, including errors.

---

## 1. Authentication

### 1.1 Employee login

```bash
# Expected: 200 OK
curl -i -X POST "$BASE_URL/api/login" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d "{\"email\": \"$EMPLOYEE_EMAIL\", \"password\": \"$DEMO_PASSWORD\"}"
```

Response (token shortened):

```json
{
  "success": true,
  "message": "Login successful.",
  "data": {
    "user": { "id": 2, "name": "Alex Employee", "email": "employee1@quantix.test", "role": "employee" },
    "token": "52|xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx",
    "token_type": "Bearer"
  }
}
```

### 1.2 Manager login

```bash
# Expected: 200 OK  (data.user.role = "manager")
curl -i -X POST "$BASE_URL/api/login" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d "{\"email\": \"$MANAGER_EMAIL\", \"password\": \"$DEMO_PASSWORD\"}"
```

### 1.3 Operations login

```bash
# Expected: 200 OK  (data.user.role = "operations")
curl -i -X POST "$BASE_URL/api/login" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d "{\"email\": \"$OPERATIONS_EMAIL\", \"password\": \"$DEMO_PASSWORD\"}"
```

### 1.4 Save a token for each user

Each login returns a new token. Store them in shell variables so the rest of the guide can use them:

```bash
login() {
  curl -s -X POST "$BASE_URL/api/login" \
    -H "Accept: application/json" \
    -H "Content-Type: application/json" \
    -d "{\"email\": \"$1\", \"password\": \"$DEMO_PASSWORD\"}" | jq -r '.data.token'
}

EMPLOYEE_TOKEN=$(login "$EMPLOYEE_EMAIL")
EMPLOYEE2_TOKEN=$(login "$EMPLOYEE2_EMAIL")
MANAGER_TOKEN=$(login "$MANAGER_EMAIL")
OPERATIONS_TOKEN=$(login "$OPERATIONS_EMAIL")
OPERATIONS2_TOKEN=$(login "$OPERATIONS2_EMAIL")

echo "Tokens saved: ${#EMPLOYEE_TOKEN} ${#MANAGER_TOKEN} ${#OPERATIONS_TOKEN} characters"
```

Without `jq`, copy `data.token` from the login response and paste it manually:

```text
EMPLOYEE_TOKEN="YOUR_EMPLOYEE_TOKEN"
MANAGER_TOKEN="YOUR_MANAGER_TOKEN"
OPERATIONS_TOKEN="YOUR_OPERATIONS_TOKEN"
```

### 1.5 Get the authenticated user

```bash
# Expected: 200 OK
curl -i "$BASE_URL/api/me" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer $EMPLOYEE_TOKEN"
```

```json
{ "success": true, "data": { "id": 2, "name": "Alex Employee", "email": "employee1@quantix.test", "role": "employee" } }
```

### 1.6 Request without a token

```bash
# Expected: 401 Unauthorized
curl -i "$BASE_URL/api/me" \
  -H "Accept: application/json"
```

```json
{ "success": false, "message": "Unauthenticated." }
```

### 1.7 Request with an invalid token

```bash
# Expected: 401 Unauthorized
curl -i "$BASE_URL/api/me" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer 999|not-a-real-token"
```

### 1.8 Invalid login

```bash
# Expected: 422 Unprocessable Content
curl -i -X POST "$BASE_URL/api/login" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d "{\"email\": \"$EMPLOYEE_EMAIL\", \"password\": \"wrong-password\"}"
```

```json
{ "success": false, "message": "Validation failed.", "errors": { "email": ["The provided credentials are incorrect."] } }
```

> Wrong credentials return **422** (not 401): `AuthController::login` throws a `ValidationException` on the `email` field.

Missing fields are also rejected with 422:

```bash
# Expected: 422 Unprocessable Content  (errors.email and errors.password)
curl -i -X POST "$BASE_URL/api/login" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"email": "not-an-email"}'
```

### 1.9 Logout

Logout revokes **only the token used for the call**. Use a separate session so the tokens saved in 1.4 keep working:

```bash
SESSION_TOKEN=$(login "$EMPLOYEE_EMAIL")

# Expected: 200 OK
curl -i -X POST "$BASE_URL/api/logout" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer $SESSION_TOKEN"
```

```json
{ "success": true, "message": "Logged out successfully." }
```

The revoked token can no longer be used:

```bash
# Expected: 401 Unauthorized
curl -i "$BASE_URL/api/me" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer $SESSION_TOKEN"
```

---

## 2. Employee operations

Request body rules (`StoreServiceRequestRequest`): `title` required, max 150 · `description` required · `category` required, max 100.

### 2.1 Create a request

```bash
# Expected: 201 Created  (status "draft")
curl -s -i -X POST "$BASE_URL/api/requests" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $EMPLOYEE_TOKEN" \
  -d '{
    "title": "Laptop replacement",
    "description": "My current laptop battery lasts less than an hour. I need a replacement for development work.",
    "category": "IT Equipment"
  }' | tee /tmp/quantix-create.txt

REQUEST_ID=$(tail -n 1 /tmp/quantix-create.txt | jq -r '.data.id')
echo "REQUEST_ID=$REQUEST_ID"
```

```json
{
  "data": {
    "id": 86,
    "title": "Laptop replacement",
    "description": "My current laptop battery lasts less than an hour. I need a replacement for development work.",
    "category": "IT Equipment",
    "status": "draft",
    "employee_id": 2,
    "assigned_to": null,
    "employee": { "id": 2, "name": "Alex Employee", "email": "employee1@quantix.test" },
    "assigned_user": null,
    "submitted_at": null,
    "completed_at": null,
    "created_at": "2026-11-13T09:15:02.000000Z",
    "updated_at": "2026-11-13T09:15:02.000000Z"
  }
}
```

### 2.2 List my requests

Employees only see their own requests. Default: newest first, 15 per page.

```bash
# Expected: 200 OK
curl -i "$BASE_URL/api/requests" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer $EMPLOYEE_TOKEN"
```

List responses are paginated:

```json
{
  "data": [ { "id": 86, "title": "Laptop replacement", "status": "draft", "...": "..." } ],
  "links": { "first": "http://127.0.0.1:8000/api/requests?page=1", "last": "...", "prev": null, "next": "..." },
  "meta": { "current_page": 1, "from": 1, "last_page": 3, "path": "http://127.0.0.1:8000/api/requests", "per_page": 15, "to": 15, "total": 40 }
}
```

### 2.3 View a request

```bash
# Expected: 200 OK  (includes employee, assigned_user and comments)
curl -i "$BASE_URL/api/requests/$REQUEST_ID" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer $EMPLOYEE_TOKEN"
```

### 2.4 Update the draft

Only the owner can edit, and only while the request is `draft` or `returned`. All fields are optional.

```bash
# Expected: 200 OK
curl -i -X PUT "$BASE_URL/api/requests/$REQUEST_ID" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $EMPLOYEE_TOKEN" \
  -d '{
    "title": "Laptop replacement - 16GB RAM",
    "description": "Battery lasts less than an hour. I need a 16GB RAM laptop for development work."
  }'
```

### 2.5 Submit the request

```bash
# Expected: 200 OK  (status "submitted", submitted_at is set)
curl -i -X POST "$BASE_URL/api/requests/$REQUEST_ID/submit" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer $EMPLOYEE_TOKEN"
```

### 2.6 Add a comment

Body rule: `body` required, max 5000.

```bash
# Expected: 201 Created
curl -i -X POST "$BASE_URL/api/requests/$REQUEST_ID/comments" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $EMPLOYEE_TOKEN" \
  -d '{"body": "Could this be handled this week? The old laptop is getting worse."}'
```

```json
{
  "message": "Comment added successfully.",
  "data": { "id": 21, "body": "Could this be handled this week? ...", "user": { "id": 2, "name": "Alex Employee" }, "created_at": "2026-11-13T09:15:04.000000Z" }
}
```

### 2.7 View activity history

Newest first, 20 per page.

```bash
# Expected: 200 OK  (created -> draft, draft -> submitted)
curl -i "$BASE_URL/api/requests/$REQUEST_ID/activities" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer $EMPLOYEE_TOKEN"
```

```json
{
  "data": [
    {
      "id": 252,
      "action": "status_changed",
      "from_status": "draft",
      "to_status": "submitted",
      "comment": null,
      "actor": { "id": 2, "name": "Alex Employee", "role": "employee" },
      "created_at": "2026-11-13T09:15:03.000000Z"
    },
    { "id": 251, "action": "created", "from_status": null, "to_status": "draft", "...": "..." }
  ],
  "links": { "...": "..." },
  "meta": { "per_page": 20, "total": 2, "...": "..." }
}
```

---

## 3. Manager operations

Decision body (`DecideServiceRequestRequest`): `decision` required, one of `approved`, `rejected`, `returned` · `comment` optional, max 2000.

A manager can only decide on **submitted** requests from **direct reports**.

### 3.1 View direct reports' requests

```bash
# Expected: 200 OK  (only requests from employee1 and employee2)
curl -i "$BASE_URL/api/requests?status=submitted" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer $MANAGER_TOKEN"
```

### 3.2 Approve a request

```bash
# Expected: 200 OK  (status "approved")
curl -i -X POST "$BASE_URL/api/requests/$REQUEST_ID/decision" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $MANAGER_TOKEN" \
  -d '{"decision": "approved", "comment": "Approved. Please go ahead."}'
```

### 3.3 Reject a request

Create and submit a second request as the employee, then reject it:

```bash
REJECT_ID=$(curl -s -X POST "$BASE_URL/api/requests" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $EMPLOYEE_TOKEN" \
  -d '{"title": "Second monitor", "description": "Requesting a 27 inch monitor for my desk.", "category": "IT Equipment"}' \
  | jq -r '.data.id')

curl -s -o /dev/null -w "submit: %{http_code}\n" -X POST "$BASE_URL/api/requests/$REJECT_ID/submit" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer $EMPLOYEE_TOKEN"

# Expected: 200 OK  (status "rejected")
curl -i -X POST "$BASE_URL/api/requests/$REJECT_ID/decision" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $MANAGER_TOKEN" \
  -d '{"decision": "rejected", "comment": "No budget for extra monitors this quarter."}'
```

### 3.4 Return a request

```bash
RETURN_ID=$(curl -s -X POST "$BASE_URL/api/requests" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $EMPLOYEE_TOKEN" \
  -d '{"title": "Jira access", "description": "Need access to Jira.", "category": "Software Access"}' \
  | jq -r '.data.id')

curl -s -o /dev/null -w "submit: %{http_code}\n" -X POST "$BASE_URL/api/requests/$RETURN_ID/submit" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer $EMPLOYEE_TOKEN"

# Expected: 200 OK  (status "returned")
curl -i -X POST "$BASE_URL/api/requests/$RETURN_ID/decision" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $MANAGER_TOKEN" \
  -d '{"decision": "returned", "comment": "Which Jira project do you need access to?"}'
```

### 3.5 Employee edits and resubmits the returned request

```bash
# Expected: 200 OK  (still "returned")
curl -i -X PUT "$BASE_URL/api/requests/$RETURN_ID" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $EMPLOYEE_TOKEN" \
  -d '{"description": "Need access to the QUANTIX Jira project."}'

# Expected: 200 OK  (returned -> submitted)
curl -i -X POST "$BASE_URL/api/requests/$RETURN_ID/submit" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer $EMPLOYEE_TOKEN"
```

---

## 4. Operations

Operations users see requests from `approved` onwards. Any Operations user can assign an approved request; only the **assigned** user can start and complete it.

Assign body (`AssignServiceRequestRequest`): `assigned_to` required, the id of a user with role `operations`.
Status body (`UpdateServiceRequestStatusRequest`): `status` required, `in_progress` or `completed` · `comment` optional, max 2000.

### 4.1 View approved requests

```bash
# Expected: 200 OK  (every item has status "approved")
curl -i "$BASE_URL/api/requests?status=approved" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer $OPERATIONS_TOKEN"
```

### 4.2 Assign the request

David (`operations@`) assigns the request to Michael (`operations2@`):

```bash
OPERATIONS2_ID=$(curl -s "$BASE_URL/api/me" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer $OPERATIONS2_TOKEN" | jq -r '.data.id')

# Expected: 200 OK  (assigned_to = OPERATIONS2_ID, status stays "approved")
curl -i -X POST "$BASE_URL/api/requests/$REQUEST_ID/assign" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $OPERATIONS_TOKEN" \
  -d "{\"assigned_to\": $OPERATIONS2_ID}"
```

### 4.3 Start work (assigned user)

```bash
# Expected: 200 OK  (status "in_progress")
curl -i -X POST "$BASE_URL/api/requests/$REQUEST_ID/status" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $OPERATIONS2_TOKEN" \
  -d '{"status": "in_progress", "comment": "Laptop ordered."}'
```

### 4.4 Complete work (assigned user)

```bash
# Expected: 200 OK  (status "completed", completed_at is set)
curl -i -X POST "$BASE_URL/api/requests/$REQUEST_ID/status" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $OPERATIONS2_TOKEN" \
  -d '{"status": "completed", "comment": "Laptop delivered and set up."}'
```

### 4.5 Check the full history

```bash
# Prints 6 activities: created, submitted, approved, assigned, in_progress, completed
curl -s "$BASE_URL/api/requests/$REQUEST_ID/activities" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer $EMPLOYEE_TOKEN" \
  | jq -r '.data | reverse | .[] | "\(.created_at)  \(.action)  \(.from_status) -> \(.to_status)  by \(.actor.name) (\(.actor.role))"'
```

---

## 5. Search, filtering, sorting and pagination

Query parameters on `GET /api/requests`:

| Parameter | Allowed values |
|---|---|
| `search` | text, max 150 — matches title, description or category, case-insensitive |
| `status` | `draft`, `submitted`, `approved`, `rejected`, `returned`, `in_progress`, `completed` |
| `sort_by` | `created_at` (default), `updated_at`, `title`, `status` |
| `sort_direction` | `asc`, `desc` (default) |
| `per_page` | 1–100 (default 15) |

Results are always limited to what the user's role may see.

```bash
# Expected: 200 OK  (only requests containing "laptop")
curl -i "$BASE_URL/api/requests?search=laptop" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer $EMPLOYEE_TOKEN"
```

```bash
# Expected: 200 OK  (same results as the lowercase search)
curl -i "$BASE_URL/api/requests?search=LAPTOP" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer $EMPLOYEE_TOKEN"
```

```bash
# Expected: 200 OK  (only "approved")
curl -i "$BASE_URL/api/requests?status=approved" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer $EMPLOYEE_TOKEN"
```

```bash
# Expected: 200 OK  (titles A-Z)
curl -i "$BASE_URL/api/requests?sort_by=title&sort_direction=asc" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer $EMPLOYEE_TOKEN"
```

```bash
# Expected: 200 OK  (newest first)
curl -i "$BASE_URL/api/requests?sort_by=created_at&sort_direction=desc" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer $EMPLOYEE_TOKEN"
```

```bash
# Expected: 200 OK  (2 items, meta.per_page = 2, links.next points to page 2)
curl -i "$BASE_URL/api/requests?per_page=2" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer $EMPLOYEE_TOKEN"
```

Combined filters. As the manager, this finds the seeded approved "VPN Access" request (category "Network Access"):

```bash
# Expected: 200 OK  (approved requests matching "access", 5 per page; links keep the filters)
curl -i "$BASE_URL/api/requests?search=access&status=approved&per_page=5" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer $MANAGER_TOKEN"
```

A compact view of a list response:

```bash
curl -s "$BASE_URL/api/requests?search=laptop&per_page=5" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer $EMPLOYEE_TOKEN" \
  | jq '{total: .meta.total, per_page: .meta.per_page, titles: [.data[].title]}'
```

---

## 6. Negative tests

Most negative tests use **seeded requests**. Their ids depend on your database, so look them up by title (as the manager, who can see both employees' requests):

```bash
seeded_id() {
  curl -s -G "$BASE_URL/api/requests" \
    -H "Accept: application/json" \
    -H "Authorization: Bearer $MANAGER_TOKEN" \
    --data-urlencode "search=$1" \
    -d sort_by=created_at -d sort_direction=asc -d per_page=100 \
    | jq -r --arg t "$1" '[.data[] | select(.title == $t)][0].id'
}

DEV_LAPTOP_ID=$(seeded_id "Development Laptop Request")      # draft     - employee1
ACCESS_REPO_ID=$(seeded_id "Access to Project Repository")   # submitted - employee2
MONITOR_ID=$(seeded_id "Additional Monitor")                 # approved  - employee1, not assigned
VPN_ID=$(seeded_id "VPN Access")                             # approved  - employee2, assigned to operations@
NETWORK_ID=$(seeded_id "Network Connectivity Issue")         # completed - employee2, assigned to operations2@
PRINTER_ID=$(seeded_id "Printer Replacement")                # rejected  - employee2
EMAIL_ID=$(seeded_id "Email Account Configuration")          # submitted - employee1

EMPLOYEE_ID=$(curl -s "$BASE_URL/api/me" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer $EMPLOYEE_TOKEN" | jq -r '.data.id')

echo "draft=$DEV_LAPTOP_ID submitted=$ACCESS_REPO_ID approved=$MONITOR_ID assigned=$VPN_ID completed=$NETWORK_ID rejected=$PRINTER_ID submitted=$EMAIL_ID"
```

> Every request in this section is **refused** by the API, so these seeded records never change.

### 6.1 Unauthorized access

```bash
# Expected: 401 Unauthorized  (no token)
curl -i "$BASE_URL/api/requests/$DEV_LAPTOP_ID" \
  -H "Accept: application/json"
```

### 6.2 Invalid request data

```bash
# Expected: 422  (errors.title and errors.description)
curl -i -X POST "$BASE_URL/api/requests" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $EMPLOYEE_TOKEN" \
  -d '{"title": "", "category": "IT Equipment"}'
```

```bash
# Expected: 422  (errors.status)
curl -i "$BASE_URL/api/requests?status=invalid" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer $EMPLOYEE_TOKEN"
```

```bash
# Expected: 422  (errors.sort_by - only whitelisted columns can be sorted)
curl -i "$BASE_URL/api/requests?sort_by=password" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer $EMPLOYEE_TOKEN"
```

```bash
# Expected: 422  (errors.sort_direction)
curl -i "$BASE_URL/api/requests?sort_direction=invalid" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer $EMPLOYEE_TOKEN"
```

```bash
# Expected: 422  (errors.per_page - must be at least 1)
curl -i "$BASE_URL/api/requests?per_page=0" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer $EMPLOYEE_TOKEN"
```

```bash
# Expected: 422  (errors.decision - "completed" is not a manager decision)
curl -i -X POST "$BASE_URL/api/requests/$EMAIL_ID/decision" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $MANAGER_TOKEN" \
  -d '{"decision": "completed"}'
```

```bash
# Expected: 422  (errors.assigned_to - the assignee must be an Operations user)
curl -i -X POST "$BASE_URL/api/requests/$MONITOR_ID/assign" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $OPERATIONS_TOKEN" \
  -d "{\"assigned_to\": $EMPLOYEE_ID}"
```

### 6.3 Invalid workflow transitions

Allowed transitions (`RequestWorkflowService`):

```text
draft     -> submitted
returned  -> submitted
submitted -> approved | rejected | returned
approved  -> in_progress
in_progress -> completed
rejected, completed -> (final)
```

Transitions the state machine refuses return **422** with `errors.status`:

```bash
# Expected: 422  "Cannot change status from rejected to submitted."  (REJECT_ID from 3.3)
curl -i -X POST "$BASE_URL/api/requests/$REJECT_ID/submit" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer $EMPLOYEE_TOKEN"
```

```bash
# Expected: 422  "Cannot change status from approved to submitted."
curl -i -X POST "$BASE_URL/api/requests/$MONITOR_ID/submit" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer $EMPLOYEE_TOKEN"
```

```bash
# Expected: 422  "Cannot change status from approved to completed."  (must start work first)
curl -i -X POST "$BASE_URL/api/requests/$VPN_ID/status" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $OPERATIONS_TOKEN" \
  -d '{"status": "completed"}'
```

Some invalid actions are blocked earlier by the policy, which also checks the current status, so they return **403**:

```bash
# Expected: 403  (manager decisions are only allowed on submitted requests)
curl -i -X POST "$BASE_URL/api/requests/$DEV_LAPTOP_ID/decision" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $MANAGER_TOKEN" \
  -d '{"decision": "approved"}'
```

```bash
# Expected: 403  (an approved request cannot be rejected)
curl -i -X POST "$BASE_URL/api/requests/$MONITOR_ID/decision" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $MANAGER_TOKEN" \
  -d '{"decision": "rejected"}'
```

```bash
# Expected: 403  (completed requests cannot be edited - REQUEST_ID was completed in 4.4)
curl -i -X PUT "$BASE_URL/api/requests/$REQUEST_ID" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $EMPLOYEE_TOKEN" \
  -d '{"title": "Changed after completion"}'
```

```bash
# Expected: 403  (completed is final - the assignee cannot restart it)
curl -i -X POST "$BASE_URL/api/requests/$NETWORK_ID/status" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $OPERATIONS2_TOKEN" \
  -d '{"status": "in_progress"}'
```

```bash
# Expected: 403  (rejected requests cannot be edited)
curl -i -X PUT "$BASE_URL/api/requests/$PRINTER_ID" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $EMPLOYEE2_TOKEN" \
  -d '{"title": "Changed after rejection"}'
```

### 6.4 Employee accessing another employee's request

The "Access to Project Repository" request belongs to employee2:

```bash
# Expected: 403  {"success": false, "message": "This action is forbidden."}
curl -i "$BASE_URL/api/requests/$ACCESS_REPO_ID" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer $EMPLOYEE_TOKEN"
```

```bash
# Expected: 403
curl -i -X PUT "$BASE_URL/api/requests/$ACCESS_REPO_ID" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $EMPLOYEE_TOKEN" \
  -d '{"title": "Changed"}'
```

```bash
# Expected: 403  (activity history follows the same rule)
curl -i "$BASE_URL/api/requests/$ACCESS_REPO_ID/activities" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer $EMPLOYEE_TOKEN"
```

```bash
# Expected: 403  (an employee cannot make manager decisions)
curl -i -X POST "$BASE_URL/api/requests/$EMAIL_ID/decision" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $EMPLOYEE_TOKEN" \
  -d '{"decision": "approved"}'
```

### 6.5 Manager accessing a non-direct report's request

The seed data has **one manager**, and both employees report to them, so this case cannot be reproduced with the demo accounts. It is covered by the automated test `test_manager_cannot_decide_request_from_another_manager` in `tests/Feature/ServiceRequestWorkflowTest.php`.

If you have a second manager account, put its token in `OTHER_MANAGER_TOKEN` and run:

```bash
if [ -n "${OTHER_MANAGER_TOKEN:-}" ]; then
  # Expected: 403
  curl -i -X POST "$BASE_URL/api/requests/$EMAIL_ID/decision" \
    -H "Accept: application/json" \
    -H "Content-Type: application/json" \
    -H "Authorization: Bearer $OTHER_MANAGER_TOKEN" \
    -d '{"decision": "approved"}'
else
  echo "Skipped: OTHER_MANAGER_TOKEN is not set (no second manager in the seed data)."
fi
```

### 6.6 Operations authorization restrictions

```bash
# Expected: 403  (VPN Access is assigned to operations@, not operations2@)
curl -i -X POST "$BASE_URL/api/requests/$VPN_ID/status" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $OPERATIONS2_TOKEN" \
  -d '{"status": "in_progress"}'
```

```bash
# Expected: 403  (nobody is assigned to Additional Monitor yet)
curl -i -X POST "$BASE_URL/api/requests/$MONITOR_ID/status" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $OPERATIONS_TOKEN" \
  -d '{"status": "in_progress"}'
```

```bash
# Expected: 403  (a manager cannot assign Operations owners)
curl -i -X POST "$BASE_URL/api/requests/$MONITOR_ID/assign" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $MANAGER_TOKEN" \
  -d "{\"assigned_to\": $OPERATIONS2_ID}"
```

```bash
# Expected: 403  (a manager cannot start Operations work)
curl -i -X POST "$BASE_URL/api/requests/$VPN_ID/status" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $MANAGER_TOKEN" \
  -d '{"status": "in_progress"}'
```

---

## 7. Notifications (optional)

The API has no notification endpoint. Notifications are **queued** (`QUEUE_CONNECTION=database`) and stored in the `notifications` table once a worker processes them:

| Event | Recipient |
|---|---|
| Submitted | The employee's manager |
| Approved | All Operations users |
| Assigned | The assigned Operations user |
| Rejected, returned, in progress, completed | The request owner |

```text
php artisan queue:work --stop-when-empty
php artisan tinker --execute="dump(App\Models\User::where('email','operations2@quantix.test')->first()->notifications()->latest()->first()?->data)"
```

Note that `queue:work` processes **every** pending job, not only the ones created by this guide.

---

## 8. Clean up

Revoke the tokens created in 1.4:

```bash
for TOKEN in "$EMPLOYEE_TOKEN" "$EMPLOYEE2_TOKEN" "$MANAGER_TOKEN" "$OPERATIONS_TOKEN" "$OPERATIONS2_TOKEN"; do
  curl -s -o /dev/null -w "logout: %{http_code}\n" -X POST "$BASE_URL/api/logout" \
    -H "Accept: application/json" \
    -H "Authorization: Bearer $TOKEN"
done

unset DEMO_PASSWORD
```

Running this guide once creates **three** service requests (`Laptop replacement`, `Second monitor`, `Jira access`) with their comments and activity history. Nothing else is changed.

---

## 9. Status code reference

| Code | Meaning in this API | Body |
|---|---|---|
| `200` | Success | `{ "data": ... }` for requests; `{ "success": true, ... }` for auth |
| `201` | Request or comment created | `{ "data": ... }` / `{ "message": ..., "data": ... }` |
| `401` | Missing, invalid or revoked token | `{ "success": false, "message": "Unauthenticated." }` |
| `403` | Policy denies the action (role, ownership, assignee or current status) | `{ "success": false, "message": "This action is forbidden." }` |
| `404` | Request id does not exist | `{ "success": false, "message": "Resource not found." }` |
| `422` | Validation error, wrong credentials or invalid status transition | `{ "success": false, "message": "Validation failed.", "errors": { ... } }` |
