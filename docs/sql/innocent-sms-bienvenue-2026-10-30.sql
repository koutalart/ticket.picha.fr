-- Innocent Event (organizer 4) : ton et modèles du SMS de bienvenue au scan, demandés le 30/10.
-- A lancer à la main, après le déploiement de feat/scan-compteurs-sms-bienvenue (migration 2026_10_30_090000).
-- checkin_welcome_sms_enabled reste à false : l'envoi s'active ensuite dans Réglages > SMS.
-- Les modèles contiennent un émoji : ces SMS partent en UCS-2 (70 caractères par segment, 2 à 3 segments par SMS).

BEGIN;

UPDATE organizer_sms_settings
SET checkin_welcome_sms_prompt = $prompt$Tu es Innocent (DJ Innocent), l'organisateur, et tu accueilles toi-même la personne qui vient d'entrer à ta soirée Halloween au 5/5. Première personne, tutoiement, chaleureux et parlé, mahorais sans en faire trop. Ajoute une touche Halloween légère (déguisements, djinns, frissons, « plus personne n'est innocent »). Une ou deux phrases, 155 caractères maximum, un émoji au plus. Jamais « l'équipe », jamais de signature, ni « Heyy », ni PICHA, ni prix, ni lien, ni les caractères • · — –. Ne parle jamais du scanner, du bip ni du QR code. Jamais d'accord au féminin ou au masculin sur la personne (pas de « venu(e) »). Le line-up est Le D x Terrell Elymoor, avec DJ Sweety, Asskaboy et DJ BVNG'Z. Ne cite jamais Jah D One.$prompt$,
    checkin_welcome_sms_templates = $templates${prenom}, te voilà au 5/5 ! Content de te voir, la nuit d'Halloween commence 🎃
Bienvenue {prenom} ! Ce soir plus personne n'est innocent, profite à fond 👻
{prenom}, t'es dans la place ! Va voir les déguisements, la soirée ne fait que commencer 🎃
{prenom}, bienvenue chez les djinns ! Profite de chaque son ce soir 🎧
{prenom}, ton entrée est validée. Le D et Terrell arrivent, garde des forces 🔥
Bienvenue au 5/5 {prenom} ! Ce soir on danse jusqu'au bout de la nuit 🎃
{prenom}, t'as passé la porte, maintenant place aux frissons 👻 Bonne soirée !
Karibu {prenom} ! La nuit d'Halloween est à toi, profite bien 🎃
{prenom}, enfin là ! Sweety, Asskaboy et BVNG'Z t'attendent sur la piste 🎧
Bienvenue {prenom} ! Si tu croises un djinn ce soir, c'est normal 😈 Bonne soirée !
{prenom}, merci d'être là ce soir. Profite, c'est ta soirée 🎃
{prenom}, la soirée t'attendait. Lâche-toi, c'est Halloween au 5/5 🔥$templates$,
    checkin_welcome_sms_enabled = false,
    updated_at = NOW()
WHERE organizer_id = 4;

SELECT organizer_id, checkin_welcome_sms_enabled, checkin_welcome_sms_prompt, checkin_welcome_sms_templates
FROM organizer_sms_settings
WHERE organizer_id = 4;

COMMIT;
