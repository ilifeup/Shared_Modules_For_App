# API contract

`openapi.yaml` describes the API implemented by `examples/poc_backend` (S1–S6) and integrated by each App.
Each App runs its own backend; this is a contract, not a shared service.

- CI (`s1-poc-backend.yml`) compares it with `php artisan route:list` — every route must be documented and every
  documented route must exist; authenticated operations must list 401, verification-gated ones 403, rate-limited ones 429.
- CI (`validate.yml`) validates it against the OpenAPI 3.1 schema.
- `x-dart` names the shared Dart client method / DTO in `packages/flutter/shared_core`:

| API | Dart |
|---|---|
| `Member` | `MemberProfile.fromJson` (`verification.*` → `verificationPolicy/Satisfied/Missing`, `profile.missing` → `missingFields`) |
| `GET /auth/policy` | `RegistrationApi.policy` → `AuthPolicy` |
| `POST /auth/register`, `/auth/email/resend`, `/auth/phone/*` | `RegistrationApi` |
| `POST /auth/login`, `/auth/logout` | `SessionApi` |
| `/auth/password/*` | `PasswordResetApi` |
| `GET /me/profile` | `MemberApi.myProfile` |
| `PATCH /me/profile`, `/me/email`, `/me/phone/*` | `ProfileApi` |
| `/me/avatar` | App `AvatarRepository` (binary / multipart, outside the JSON transport) |
| `/me/membership`, `/membership-levels` | `MembershipApi` → `Membership`, `MembershipLevel` |
| `/notices`, `/notices/{id}/read`, `/public/notices`, `/notice-categories` | `NoticeApi` → `NoticePage`, `Notice`, `NoticeCategory` (`MemberApi.notices/notice` unchanged) |
| `/me/notifications*` | `NotificationApi` → `NotificationPage`, `MemberNotification` |
| `/events*`, `/public/events*` | `EventApi` → `EventRange`, `CalendarEvent` |
| `/events/{id}/booking`, `/events/{id}/bookings`, `/me/bookings*` | `BookingApi` → `BookingInfo`, `Booking` |
| `Error` | App `ApiException` (`code`, `message`, `fields`, `requestId`, `statusCode`) |
