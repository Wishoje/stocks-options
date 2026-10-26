# SMTP transport configuration

Mailtrap Email Sending uses `live.smtp.mailtrap.io:587` with STARTTLS. The SMTP connection upgrades to TLS before authentication. Immediate SMTPS on that endpoint fails before authentication.

Laravel 11.35 interprets the legacy `encryption=tls` setting as the `smtps` scheme when no scheme is provided. The application therefore selects its SMTP scheme explicitly: `smtps` for port 465 and `smtp` for other ports. Symfony's automatic STARTTLS upgrade remains enabled, and certificate verification is unchanged. `MAIL_SCHEME` can override the default for providers using a nonstandard implicit TLS port.

No password, sender address or provider change is required for this correction. Rebuild configuration caches on both web and worker releases after deployment and gracefully restart queue workers. Existing failed messages must be reviewed separately before retrying; a bulk retry can send stale lifecycle messages.

Verify each server's configured transport and authenticate without sending a message. Then send one clearly labelled test email to the operator's address through the production mailer. SMTP acceptance confirms submission; receiving-mailbox evidence is needed to confirm inbox delivery.

`SmtpTransportConfigurationTest` covers Mailtrap port 587 with the legacy `tls` setting, standard implicit TLS on port 465, STARTTLS on port 2525, and an explicit scheme override. These tests construct transports without connecting to an SMTP server.

Provider reference: [Mailtrap Email Sending overview](https://docs.mailtrap.io/email-api-smtp/overview).
