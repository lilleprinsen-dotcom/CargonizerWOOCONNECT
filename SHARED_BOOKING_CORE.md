# Shared booking core (1.3.0)

The Connector owns shipment rules and provider communication. WordPress Admin and Woo Ops are transport adapters only.

## Call paths

- WordPress Admin verifies `manage_woocommerce` and the existing nonce, creates a unique admin idempotency key, then calls `LP_Cargonizer_Operations_Facade::book_shipment()`.
- Operations Bridge authenticates the Woo Ops request and calls the same facade with the employee/device actor and Woo Ops idempotency key.
- The facade enforces idempotency and the connector-level lock, then both paths enter `execute_shared_booking_core()`.

The shared core owns order/recipient resolution, package normalization, current sender/method re-resolution, services, SMS, servicepartner selection, notification, booking XML, provider execution, DirectPrint, status behavior, booking history, tracking, internal notes and customer-visible tracking notes.

The shared estimator path is `execute_shared_estimate_core()`. Both Admin and Woo Ops use it, including current method eligibility, pricing configuration, manual Norgespakke rules and DSV/Bring estimator behavior.

## Safe preflight

Woo Ops facade requests omit `confirm_execution` unless the Operations API's separately guarded shipping action mode explicitly authorizes execution. The core performs authoritative validation and XML construction, then returns `preflight_ready` with a SHA-256 of the provider payload. It does not call the booking endpoint and does not persist a consignment, tracking, label, print, booking history, order status or order note.

The normal WordPress Admin flow always sends an explicit execution confirmation after its existing permission and nonce checks, so production Admin behavior remains available.

## Idempotency

Woo Ops must provide an idempotency key. The fingerprint excludes transport-only `confirm_execution` and actor fields. A preflight is not persisted as a completed booking, so the same logical request can later execute with the same key. A completed request replays its stored result; changed content under the same key conflicts.

Admin creates a fresh key for every deliberate button action, preserving intentional multiple bookings while still using the same lock/idempotency boundary.

## Testing

`php tests/phase17c-parity.php` validates the 18 required deterministic Admin/facade contracts and verifies the shared-core/preflight structure. `php tests/idempotency.php` validates preflight, create, replay and changed-payload conflict without loading WordPress or calling any provider.

