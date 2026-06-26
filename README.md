# Member Receipt Claim Form

## Shortcode

Use:

```text
[receipt_claim_form]
```

Optional attributes:

```text
[receipt_claim_form title="Claim Missing Loyalty Points" desc="Forgot to attach your loyalty account during your last visit? Enter your receipt details below to retroactively claim your points." button="Submit Points"]
```

## Exact Flow

1. The shortcode renders a custom `novalidate` form with one receipt field and one loyalty identifier choice.
2. Front-end JavaScript validates the selected input before submit:
   - `tabRef` is required
   - `cardNumber` is reduced to digits only
   - `mobilePhone` is normalized to the same format the backend accepts
   - `email` is trimmed and validated
3. The browser posts JSON to the WordPress REST endpoint with:
   - `tabRef`
   - `loyaltyType`
   - exactly one of `cardNumber`, `mobilePhone`, or `email`
   - the honeypot field
4. The REST handler verifies the nonce, honeypot field, and rate limit.
5. The handler resolves the loyalty type. If the radio value is missing, it infers the type from the submitted field names.
6. The plugin authenticates to GoTab using the configured API access credentials and caches the bearer token.
7. The plugin queries GoTab `tabsList` by `tabRef`.
   - If `GoTab Location ID` is configured, the query is scoped to that location.
   - If the lookup returns 0 matches, the plugin returns the configured GoTab not found message.
   - If the lookup returns multiple matches, the plugin returns a multiple-match error.
8. The plugin checks the receipt date against the configured claim window using the selected GoTab date field (`created`, `updated`, or `fiscalDay`).
9. The plugin resolves the Clutch card number:
   - `cardNumber`: used directly
   - `mobilePhone`: searched in Clutch with `phone` OR `mobilePhone`
   - `email`: searched in Clutch by `email`
10. If Clutch returns exactly one card number, the plugin calls Clutch `/flex/barcodeClaim` with:
    - `externalId = GoTab tabId`
    - `cardNumber = resolved Clutch card number`
11. The claim is considered successful only when Clutch returns both:
    - `success = true`
    - `checkoutWasClaimed = true`
12. Every attempt is logged. When Debug Mode is enabled, the upstream GoTab or Clutch response body is stored and shown in the Logs tab.

## Required Settings

Go to **Settings -> Receipt Claim** and configure:

- Active Environment
- Clutch stage credentials and headers
- Clutch production credentials and headers
- GoTab stage URLs and credentials
- GoTab production URLs and credentials
- Claim duration amount and unit

## Important Notes

- All external API calls are server-side.
- GoTab Location ID is strongly recommended and may be required for reliable `tabRef` lookups.
- Clutch Card Set ID(s) can be stored per environment, but the current workflow does not send them in requests.
- The plugin currently sends GoTab `tabId` as Clutch `externalId` in `/flex/barcodeClaim`.
- The plugin auto-creates its log table if it does not exist yet.
- The Logs tab shows both the plugin message and the raw upstream server response when Debug Mode is enabled.
- The plugin hashes IP addresses and does not intentionally log raw phone or email values.
- For production, consider adding a real CAPTCHA or WAF rule in addition to the included honeypot and rate limit.
