CHANGELOG
=========

Unreleased
----------

* initial development - the Symfony Mailer transport, Email to Transmission conversion,
  SparkPostEmail and the sink envelope listener, with a rig exercise that drives a real
  send through the live API
* requires hampel/sparkpost ^0.1.0, published to Packagist on 21 August 2026, and
  symfony/mailer and symfony/mime at ^5.4|^6.4|^7.0 - 5.4 because that is what XenForo 2.3
  bundles and a XenForo add-on cannot substitute its own
