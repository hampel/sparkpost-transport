CHANGELOG
=========

0.1.0 (2026-08-22)
------------------

* initial development - the Symfony Mailer transport, Email to Transmission conversion,
  SparkPostEmail and the sink envelope listener, with rig exercises that drive real sends
  through the live API
* a transmission SparkPost accepts with no accepted recipients is a failed send
* every recipient receives the same To: line, Cc is visible, and Bcc appears in no header
* SparkPostEmail carries campaign, description, return path, tracking, sandbox, metadata
  and substitution data, and survives serialisation
* requires hampel/sparkpost ^0.1.0, symfony/mailer and symfony/mime ^5.4|^6.4|^7.0
