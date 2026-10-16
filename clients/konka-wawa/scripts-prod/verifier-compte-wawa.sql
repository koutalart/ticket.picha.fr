-- Konka Wawa : confirmation manuelle du compte (lien de confirmation e-mail cassé hors connexion).
-- Utilisateur 10 (wawakonkaevent@gmail.com) + compte 6. Tout s'annule si un contrôle échoue.
\set ON_ERROR_STOP on
BEGIN;
DO $$
DECLARE n int;
BEGIN
  UPDATE users SET email_verified_at = now(), updated_at = now()
   WHERE id = 10 AND email = 'wawakonkaevent@gmail.com' AND email_verified_at IS NULL;
  GET DIAGNOSTICS n = ROW_COUNT; IF n <> 1 THEN RAISE EXCEPTION 'users: % ligne(s)', n; END IF;

  UPDATE accounts SET account_verified_at = now(), is_manually_verified = true, updated_at = now()
   WHERE id = 6 AND name = 'Konka Wawa' AND account_verified_at IS NULL;
  GET DIAGNOSTICS n = ROW_COUNT; IF n <> 1 THEN RAISE EXCEPTION 'accounts: % ligne(s)', n; END IF;
END $$;
COMMIT;
SELECT id, email, email_verified_at FROM users WHERE id = 10;
SELECT id, name, account_verified_at, is_manually_verified FROM accounts WHERE id = 6;
