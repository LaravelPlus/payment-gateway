# Release checklist

1. **CI is green** on every PHP × Laravel combination in `.github/workflows/tests.yml`.
2. **Capabilities are true.** `docs/capabilities.md` passes `CapabilityMatrixTest`, and any new behaviour has a row under "Behaviour covered by tests".
3. **Migrations.** New migrations are additive and guarded (`Schema::hasColumn`) for apps that patched around the old schema. Run them once on PostgreSQL:
   `DB_CONNECTION=pgsql vendor/bin/phpunit` against a scratch database.
4. **Breaking changes.** Every `!` commit since the last tag has a `BREAKING CHANGE:` note. List them first in the release notes, with the action apps must take (run `migrate`, redefine `viewPaymentAdmin`, review `cleanup-subscriptions` scheduling, …).
5. **Sandbox pass** with test-mode keys, never live:
   - Stripe: subscription checkout with a trial, one-time checkout, a partial refund, `stripe trigger` for `invoice.paid` and `charge.refunded`.
   - PayPal sandbox: subscription created → activated → cancelled, one capture and refund.
   - Confirm each webhook returns 2xx once and 200 `duplicate` on redelivery.
6. **No secrets.** `gitleaks git -v` over the full history.
7. **Tag** `vX.Y.Z` and publish the GitHub release. Packagist updates from its GitHub hook.
