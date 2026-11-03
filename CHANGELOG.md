CHANGELOG
=========

1.1.0 (2026-11-03)
------------------

* `EmailConverter` takes a second argument, `$escapeTemplateSyntax`, default `false`. When `true`,
  double curly braces in the subject, text, html, custom header values and the From, Cc and Reply-To
  display names are replaced with SparkPost's escape macros so they render literally, except on a
  `SparkPostEmail` carrying substitution data. Off by default, so nothing an application sends
  changes unless it opts in
* requires `hampel/sparkpost` `^1.2.1`. An application that changes nothing will now send a To, Cc
  or Reply-To display name containing a comma, period or other RFC 5322 special as a quoted string -
  `"Smith, John" <john@example.com>` where it previously sent `Smith, John <john@example.com>`,
  which a mail client reads as two addresses

1.0.0 (2026-09-04)
------------------

* the API this package exposes is now stable, and versioned under semver rather than 0.x. The
  transport, `SparkPostEmail`, `EmailConverter` and `SinkEnvelopeListener` are unchanged from
  0.5.0 - nothing in `src/` moved for this release
* requires `hampel/sparkpost` `^1.0`; `^0.4.0` is no longer supported. The dependency reaching 1.0.0
  is what allows this one to: a stable package resting on a 0.x dependency over-promises, which
  is why the package stayed 0.x until now
* the constraint on `hampel/sparkpost` is no longer one minor wide. Below 1.0 a caret cannot reach
  the next minor, so each API-package release needed a coordinated bump here; `^1.0` picks up
  1.x minors without an edit

0.5.0 (2026-09-04)
------------------

* requires `hampel/sparkpost` `^0.4.0`; `^0.3.0` is no longer supported

0.4.0 (2026-08-27)
------------------

* declares `symfony/event-dispatcher`, `psr/log` and `psr/event-dispatcher`, used in `src/` and
  previously undeclared
* requires `hampel/sparkpost` `^0.3.0`; `^0.1.0` and `^0.2.0` are no longer supported

0.3.0 (2026-08-23)
------------------

* the envelope sender is sent as the transmission `return_path`, so `Email::returnPath()` and
  `Email::sender()` now reach SparkPost - previously both were discarded
* a message setting a bounce domain that is not configured on the account is accepted by
  SparkPost, which discards the value and sends under its own default bounce domain
  instead. The fallback aligns with nothing, so the message can still be refused
  downstream by a receiver enforcing DMARC
* allow `hampel/sparkpost` `^0.2.0`

0.2.0 (2026-08-22)
------------------

* `EmailConverter` accepts default transmission options, applied to every message and
  overridden by anything the message itself carries

0.1.0 (2026-08-22)
------------------

* initial development - the Symfony Mailer transport, `Email` to `Transmission` conversion,
  `SparkPostEmail` and the sink envelope listener, with `rig` exercises that drive real sends
  through the live API
* a transmission SparkPost accepts with no accepted recipients is a failed send
* every recipient receives the same `To:` line, `Cc` is visible, and `Bcc` appears in no header
* `SparkPostEmail` carries campaign, description, return path, tracking, sandbox, metadata
  and substitution data, and survives serialisation
* requires `hampel/sparkpost` `^0.1.0`, `symfony/mailer` and `symfony/mime` `^5.4|^6.4|^7.0`
