# Security Policy

## Reporting a vulnerability

Email **hello@cdgrph.com** with the subject line “Turnstile Pass security report.” Please do not open a public issue or disclose the vulnerability publicly before a fix is available.

Include the affected version, reproduction steps or a proof of concept, the expected and observed behavior, and an impact assessment if available. Remove site credentials, Turnstile secret keys, personal data, and other sensitive information from the report.

## What to expect

We will acknowledge the report within two business days (JST), investigate it privately, and keep you informed about confirmed impact and remediation. Confirmed vulnerabilities are prioritized ahead of routine maintenance.

## Security considerations

- Keep the Turnstile secret key private and store it in an environment variable where possible.
- Perform verification on the server. Client-side widget completion alone is not proof of a valid request.
- Keep Turnstile Pass, Craft CMS, PHP, and related dependencies up to date.
