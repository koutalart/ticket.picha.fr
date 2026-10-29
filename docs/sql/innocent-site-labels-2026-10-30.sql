-- Innocent Event (organizer 4): textes du thème validés par Jo le 30/10.
-- A lancer à la main, après le déploiement de feat/textes-site-par-organisateur.
-- seo_title_contact et seo_title_services restent volontairement absents (textes par défaut conservés).

BEGIN;

UPDATE organizers
SET site_content = COALESCE(site_content, '{}'::jsonb) || jsonb_build_object(
        'seo_title_home', 'Innocent Event | Concerts et soirées à Mayotte, billetterie officielle',
        'seo_title_partners', 'Partenaires et sponsoring | Innocent Event',
        'seo_title_events', 'Événements et concerts à Mayotte | Innocent Event',
        'seo_title_about', 'Qui sommes-nous | Innocent Event, Mayotte',
        'about_button_label', 'Notre histoire',
        'partners_subtitle', 'Ils nous font confiance.',
        'services_cta_title', 'Ton prochain événement, on le monte ensemble ?',
        'services_cta_text', 'Raconte-nous ton projet. On te répond avec une proposition sur mesure.',
        'partner_card_1_text', 'Rencontre notre public au cœur de la fête.',
        'partner_card_2_text', 'Sur place, sur nos affiches et sur nos réseaux.',
        'partner_card_3_text', 'Sponsoring, co-branding, invitations. On le construit ensemble.',
        'partners_button_label', 'Devenir partenaire',
        'contact_intro', 'Une question sur une soirée, un billet ou un projet ? On te répond.',
        'contact_write_text', 'Écris-nous, on te répond vite par e-mail.',
        'events_intro', 'Nos prochaines dates et nos soirées passées.'
    ),
    updated_at = NOW()
WHERE id = 4;

SELECT id, name, site_content - 'story' - 'vision' AS site_content
FROM organizers
WHERE id = 4;

COMMIT;
