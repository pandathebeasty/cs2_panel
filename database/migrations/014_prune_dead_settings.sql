-- One-time cleanup of settings rows the panel no longer uses.
  
-- Going forward this is handled automatically in PHP by Settings::prune()
-- (called from Schema::sync after migrations), which derives the valid set
-- from defaults() + runtime keys and drops anything else. This migration is
-- the explicit, recorded cleanup of the known legacy keys that existed before
-- prune() shipped, so a hand-uploaded panel is tidied even on the first load.
  
-- Keys removed:
--   store_paypal_client_id / _secret / _webhook_id  -> pre-split PayPal (now
--       stored per-environment as store_paypal_{sandbox,live}_*)
--   contest_cron_token                              -> contests reuse cron_token
--   contest_autoadd_days                            -> abandoned contest option
-- Safe no-op if the rows are already gone.

DELETE FROM `panel_settings` WHERE `key` IN (
    'store_paypal_client_id',
    'store_paypal_secret',
    'store_paypal_webhook_id',
    'contest_cron_token',
    'contest_autoadd_days'
);
