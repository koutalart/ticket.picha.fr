/* eslint-disable lingui/no-unlocalized-strings */
import {ReactNode} from "react";
import {legalEntity as e} from "./legalEntity.ts";

export type LegalSlug = "mentions-legales" | "cgu" | "cgv" | "confidentialite" | "cookies";

export interface LegalDocument {
    slug: LegalSlug;
    title: string;
    content: ReactNode;
}

const Mail = ({address}: { address: string }) => <a href={`mailto:${address}`}>{address}</a>;

const mentionsLegales: LegalDocument = {
    slug: "mentions-legales",
    title: "Mentions légales",
    content: (
        <>
            <p>
                Conformément à l'article 6 de la loi n° 2004-575 du 21 juin 2004 pour la confiance dans
                l'économie numérique (LCEN), les informations suivantes sont portées à la connaissance des
                utilisateurs du service {e.brand}.
            </p>

            <h2>Éditeur</h2>
            <p>
                {e.companyName}, {e.legalForm} au capital de {e.shareCapital}<br/>
                Siège social : {e.address}<br/>
                Immatriculation : {e.registration} — SIREN : {e.siren}<br/>
                N° de TVA intracommunautaire : {e.vatNumber}<br/>
                Téléphone : {e.phone} — E-mail : <Mail address={e.email}/>
            </p>

            <h2>Directeur de la publication</h2>
            <p>{e.publicationDirector}</p>

            <h2>Hébergeur</h2>
            <p>
                {e.host.name}, {e.host.address} — <a href={e.host.website} target="_blank" rel="noreferrer">{e.host.website}</a>
            </p>

            <h2>Propriété intellectuelle</h2>
            <p>
                La marque {e.brand}, les logos, la charte graphique et les contenus du service sont protégés
                par le droit de la propriété intellectuelle. Toute reproduction ou représentation, totale ou
                partielle, sans autorisation préalable est interdite. Les contenus publiés par les
                organisateurs (textes, images, visuels d'événements) restent sous leur responsabilité.
            </p>

            <h2>Signalement d'un contenu illicite</h2>
            <p>
                Tout contenu manifestement illicite peut être signalé à <Mail address={e.email}/> en précisant
                l'adresse de la page concernée et les motifs du signalement.
            </p>
        </>
    ),
};

const cgu: LegalDocument = {
    slug: "cgu",
    title: "Conditions générales d'utilisation",
    content: (
        <>
            <h2>1. Objet</h2>
            <p>
                Les présentes conditions générales d'utilisation (CGU) encadrent l'accès et l'utilisation du
                service {e.brand}, plateforme de billetterie et de gestion d'événements éditée par
                {" "}{e.companyName} (l'« Éditeur »). Elles s'appliquent à tout visiteur, acheteur,
                participant et organisateur. L'achat de billets est en outre régi par les
                {" "}<a href="/legal/cgv">conditions générales de vente</a>.
            </p>

            <h2>2. Rôle de la plateforme</h2>
            <p>
                {e.brand} fournit aux organisateurs un outil technique de création d'événements, de vente de
                billets, de contrôle d'accès et de vente sur place (guichet). Chaque événement est organisé
                sous la seule responsabilité de son organisateur, qui en est le vendeur et le responsable
                vis-à-vis des participants.
            </p>

            <h2>3. Compte organisateur</h2>
            <p>
                La création d'un compte suppose des informations exactes et à jour. L'organisateur est
                responsable de la confidentialité de ses identifiants et des actions réalisées depuis son
                compte, y compris par les opérateurs de guichet qu'il invite. Il s'engage à :
            </p>
            <ul>
                <li>organiser des événements licites et respecter les réglementations applicables (sécurité,
                    capacité d'accueil, autorisations, droits d'auteur, fiscalité) ;</li>
                <li>publier des informations exactes sur l'événement, les tarifs et les conditions d'accès ;</li>
                <li>respecter les droits des consommateurs, notamment en cas d'annulation ou de report ;</li>
                <li>traiter les données des participants conformément au RGPD, en tant que responsable de
                    traitement pour ses propres finalités.</li>
            </ul>

            <h2>4. Utilisation acceptable</h2>
            <p>
                Il est interdit de détourner le service, de tenter d'en compromettre la sécurité, d'extraire
                massivement des données, de publier des contenus illicites ou trompeurs, ou de revendre des
                billets en violation de l'article 313-6-2 du Code pénal.
            </p>

            <h2>5. Disponibilité</h2>
            <p>
                L'Éditeur met en œuvre des moyens raisonnables pour assurer l'accès au service mais ne peut
                garantir une disponibilité continue, notamment en cas de maintenance, de panne ou de force
                majeure.
            </p>

            <h2>6. Suspension</h2>
            <p>
                L'Éditeur peut suspendre ou clôturer un compte en cas de manquement grave aux présentes CGU,
                d'utilisation frauduleuse ou d'événement manifestement illicite, après information de
                l'intéressé sauf urgence.
            </p>

            <h2>7. Responsabilité</h2>
            <p>
                L'Éditeur est responsable du bon fonctionnement technique du service. Il n'est pas responsable
                de la tenue, du contenu ou du déroulement des événements, qui relèvent de l'organisateur.
            </p>

            <h2>8. Données personnelles</h2>
            <p>
                Les traitements de données sont décrits dans la
                {" "}<a href="/legal/confidentialite">politique de confidentialité</a>.
            </p>

            <h2>9. Modification des CGU</h2>
            <p>
                Les CGU peuvent évoluer. La version applicable est celle en ligne au moment de l'utilisation ;
                les organisateurs sont informés des modifications substantielles.
            </p>

            <h2>10. Droit applicable</h2>
            <p>
                Les présentes CGU sont soumises au droit français. À défaut de résolution amiable, les
                tribunaux français sont compétents, sous réserve des règles protectrices applicables aux
                consommateurs.
            </p>
        </>
    ),
};

const cgv: LegalDocument = {
    slug: "cgv",
    title: "Conditions générales de vente",
    content: (
        <>
            <h2>1. Objet et parties</h2>
            <p>
                Les présentes conditions générales de vente (CGV) s'appliquent à l'achat de billets sur
                {" "}{e.brand}, en ligne ou au guichet. Le billet est vendu par l'organisateur de l'événement
                (le « Vendeur »), dont l'identité figure sur la page de l'événement ; {e.companyName} fournit
                la plateforme technique de vente pour son compte. Toute commande vaut acceptation des CGV.
            </p>

            <h2>2. Commande</h2>
            <p>
                L'acheteur sélectionne ses billets, renseigne les informations demandées puis valide sa
                commande. Un récapitulatif est affiché avant paiement. La commande est confirmée par l'envoi
                d'un e-mail contenant le ou les billets, sauf vente au guichet sans adresse e-mail, où le
                billet est remis sur place.
            </p>

            <h2>3. Prix</h2>
            <p>
                Les prix sont indiqués en euros, toutes taxes comprises. Les éventuels frais de service sont
                affichés distinctement avant la validation de la commande.
            </p>

            <h2>4. Paiement</h2>
            <p>
                Le paiement en ligne par carte est traité par un prestataire de paiement sécurisé ; les
                données de carte ne sont pas conservées par {e.brand}. L'organisateur peut proposer un
                paiement hors ligne (virement, espèces…) : la commande est alors confirmée à réception du
                paiement selon les instructions indiquées. Au guichet, le paiement s'effectue sur place.
            </p>

            <h2>5. Billets et accès</h2>
            <p>
                Chaque billet comporte un code unique contrôlé à l'entrée ; il ne permet qu'un seul accès.
                Toute reproduction peut entraîner un refus d'accès au porteur de la copie. Le participant doit
                respecter le règlement du lieu et de l'événement.
            </p>

            <h2>6. Absence de droit de rétractation</h2>
            <p>
                Conformément à l'article L221-28, 12° du Code de la consommation, le droit de rétractation ne
                s'applique pas aux prestations de services de loisirs fournies à une date ou à une période
                déterminée. Les billets ne sont donc ni repris ni échangés, sauf dans les cas prévus à
                l'article 7.
            </p>

            <h2>7. Annulation, report ou modification de l'événement</h2>
            <p>
                En cas d'annulation de l'événement, le prix du billet est remboursé par l'organisateur. En cas
                de report ou de modification substantielle, l'organisateur informe les acheteurs et précise
                les modalités applicables, dans le respect de la réglementation en vigueur. Les demandes sont
                à adresser à l'organisateur ou à <Mail address={e.email}/>, qui les transmettra.
            </p>

            <h2>8. Revente</h2>
            <p>
                La revente habituelle de billets sans l'autorisation de l'organisateur est interdite et punie
                par l'article 313-6-2 du Code pénal.
            </p>

            <h2>9. Réclamations et médiation</h2>
            <p>
                Toute réclamation peut être adressée à <Mail address={e.email}/>. Conformément aux articles
                L611-1 et suivants du Code de la consommation, en cas de litige non résolu, le consommateur peut
                recourir gratuitement au médiateur de la consommation : {e.mediator.name}, {e.mediator.address},
                {" "}{e.mediator.website}.
            </p>

            <h2>10. Droit applicable</h2>
            <p>
                Les présentes CGV sont soumises au droit français. Le consommateur peut saisir, à son choix,
                la juridiction du lieu où il demeurait lors de la conclusion du contrat ou de la survenance du
                fait dommageable.
            </p>
        </>
    ),
};

const confidentialite: LegalDocument = {
    slug: "confidentialite",
    title: "Politique de confidentialité",
    content: (
        <>
            <p>
                Cette politique décrit comment les données personnelles sont traitées sur {e.brand},
                conformément au règlement (UE) 2016/679 (RGPD) et à la loi n° 78-17 du 6 janvier 1978
                « Informatique et Libertés ».
            </p>

            <h2>1. Qui est responsable ?</h2>
            <ul>
                <li><strong>{e.companyName}</strong> est responsable des traitements liés au fonctionnement de
                    la plateforme : comptes organisateurs, sécurité, facturation de ses services, obligations
                    légales.</li>
                <li><strong>L'organisateur de chaque événement</strong> est responsable des traitements des
                    données de ses acheteurs et participants ; {e.companyName} agit alors comme sous-traitant
                    pour son compte (article 28 du RGPD).</li>
            </ul>

            <h2>2. Données traitées</h2>
            <ul>
                <li>Identité et contact : nom, prénom, e-mail, téléphone (facultatif au guichet) ;</li>
                <li>Commande : billets, montants, mode de paiement (sans conservation des numéros de carte),
                    historique d'accès à l'événement (contrôle des billets) ;</li>
                <li>Réponses aux questions posées par l'organisateur lors de l'inscription ;</li>
                <li>Données techniques : adresse IP, journaux de connexion, préférences (langue).</li>
            </ul>

            <h2>3. Finalités et bases légales</h2>
            <ul>
                <li>Gestion des commandes, envoi des billets, contrôle d'accès : exécution du contrat
                    (art. 6.1.b) ;</li>
                <li>Comptabilité et facturation : obligation légale (art. 6.1.c) ;</li>
                <li>Sécurité du service et prévention de la fraude : intérêt légitime (art. 6.1.f) ;</li>
                <li>Communications marketing et traceurs non essentiels : consentement (art. 6.1.a), retirable
                    à tout moment.</li>
            </ul>

            <h2>4. Destinataires</h2>
            <p>
                Les données sont accessibles à l'organisateur de l'événement concerné et, pour les besoins du
                service, aux prestataires techniques : hébergement ({e.host.name}, en France), envoi
                d'e-mails et paiement en ligne. Ces prestataires agissent sur instruction et sont tenus à la
                confidentialité.
            </p>

            <h2>5. Transferts hors Union européenne</h2>
            <p>
                Certains prestataires (paiement, envoi d'e-mails) peuvent traiter des données hors de l'Union
                européenne. Ces transferts sont encadrés par une décision d'adéquation ou par les clauses
                contractuelles types de la Commission européenne.
            </p>

            <h2>6. Durées de conservation</h2>
            <ul>
                <li>Compte organisateur : pendant l'utilisation du service, puis 3 ans après le dernier
                    contact ;</li>
                <li>Commandes et pièces comptables : 10 ans (article L123-22 du Code de commerce) ;</li>
                <li>Données des participants : durée nécessaire à l'événement et à son suivi, puis archivage
                    selon les instructions de l'organisateur et les obligations légales ;</li>
                <li>Journaux techniques : 1 an ; consentement aux cookies : 6 mois.</li>
            </ul>

            <h2>7. Vos droits</h2>
            <p>
                Vous disposez des droits d'accès, de rectification, d'effacement, de limitation, de
                portabilité et d'opposition, du droit de retirer votre consentement et de définir des
                directives relatives au sort de vos données après votre décès. Pour les exercer, écrivez à
                {" "}<Mail address={e.privacyContactEmail}/> ; pour les données liées à un événement, la
                demande est transmise à l'organisateur concerné. Vous pouvez introduire une réclamation auprès
                de la CNIL (<a href="https://www.cnil.fr" target="_blank" rel="noreferrer">www.cnil.fr</a>).
            </p>

            <h2>8. Sécurité</h2>
            <p>
                Des mesures techniques et organisationnelles adaptées protègent les données : chiffrement des
                échanges (HTTPS), contrôle des accès par rôle, sauvegardes régulières.
            </p>

            <h2>9. Cookies</h2>
            <p>
                L'utilisation des cookies et traceurs est détaillée dans la
                {" "}<a href="/legal/cookies">politique cookies</a>.
            </p>
        </>
    ),
};

const cookies: LegalDocument = {
    slug: "cookies",
    title: "Politique cookies",
    content: (
        <>
            <p>
                Conformément à l'article 82 de la loi « Informatique et Libertés » et aux recommandations de
                la CNIL, les traceurs non essentiels ne sont déposés qu'avec votre consentement, que vous
                pouvez refuser aussi simplement que l'accepter.
            </p>

            <h2>1. Traceurs strictement nécessaires (sans consentement)</h2>
            <ul>
                <li>Session et authentification (connexion à votre compte) ;</li>
                <li>Déroulement de la commande (panier, réservation temporaire des billets) ;</li>
                <li>Préférences : langue d'affichage ;</li>
                <li>Mémorisation de votre choix sur les cookies.</li>
            </ul>

            <h2>2. Traceurs soumis à consentement</h2>
            <p>
                Un organisateur peut activer des outils de mesure d'audience ou des pixels publicitaires sur
                ses pages d'événement. Ils ne sont chargés qu'après votre accord via le bandeau cookies.
            </p>

            <h2>3. Durée</h2>
            <p>
                Votre choix est conservé 6 mois ; les traceurs soumis à consentement ont une durée de vie
                maximale de 13 mois.
            </p>

            <h2>4. Gérer vos choix</h2>
            <p>
                Vous pouvez modifier votre choix à tout moment en supprimant les cookies de ce site depuis les
                réglages de votre navigateur : le bandeau vous sera de nouveau proposé.
            </p>
        </>
    ),
};

export const legalDocuments: Record<LegalSlug, LegalDocument> = {
    "mentions-legales": mentionsLegales,
    cgu,
    cgv,
    confidentialite,
    cookies,
};
