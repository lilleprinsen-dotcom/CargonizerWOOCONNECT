# AGENTS.md

## Repository instructions

- This repository contains a WordPress/WooCommerce plugin.
- Preserve runtime behavior exactly unless a prompt explicitly allows otherwise.
- Authentication is immutable. Do not change authentication flow, auth headers, auth storage, endpoint URLs, sender/API key handling, or anything related to current auth behavior.
- Preserve all current nonce action strings, AJAX action names, option keys, hook names, admin page slug, and response payload keys.
- Preserve all current business logic exactly, including:
  - pricing logic
  - price-source selection and fallback order
  - manual Norgespakke logic
  - Bring manual handling logic
  - DSV optimization logic
  - servicepartner behavior
  - SMS-service behavior
  - XML request/response handling
- Structural refactors are allowed only if behavior stays 1:1 identical.
- Prefer small, reversible changes.
- After each task:
  - list touched files
  - state which invariants were preserved
  - run the narrowest relevant validation available
  - stop and wait for the next prompt
- Do not rename public identifiers unless absolutely required for safe extraction, and if so preserve behavior exactly.
- If there is any doubt between cleaner code and exact behavior parity, choose exact behavior parity.
