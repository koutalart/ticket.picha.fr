import {useMemo, useState} from "react";
import {useParams, useSearchParams} from "react-router";
import {t} from "@lingui/macro";
import {Alert, Button, Skeleton, Text, TextInput} from "@mantine/core";
import {IconBulb, IconFolderPlus, IconPlus, IconQrcode, IconSearch, IconX} from "@tabler/icons-react";
import {IdParam, QrCampaign, QrLink} from "../../../../types.ts";
import {useGetQrLinks} from "../../../../queries/useGetQrLinks.ts";
import {useGetQrCampaigns} from "../../../../queries/useGetQrCampaigns.ts";
import {useGetOrganizer} from "../../../../queries/useGetOrganizer.ts";
import {useUpdateQrLink} from "../../../../mutations/useUpdateQrLink.ts";
import {useDeleteQrLink} from "../../../../mutations/useDeleteQrLink.ts";
import {useDeleteQrCampaign} from "../../../../mutations/useDeleteQrCampaign.ts";
import {showError, showSuccess} from "../../../../utilites/notifications.tsx";
import {confirmationDialog} from "../../../../utilites/confirmationDialog.tsx";
import {formatNumber} from "../../../../utilites/helpers.ts";
import {PageBody} from "../../../common/PageBody";
import {QrLinkRow} from "./QrLinkRow.tsx";
import {QrLinkFormModal, QrPosterBackground} from "./QrLinkFormModal.tsx";
import {QrLinkStatsDrawer} from "./QrLinkStatsDrawer.tsx";
import {QrCampaignModal} from "./QrCampaignModal.tsx";
import {QrCampaignCard} from "./QrCampaignCard.tsx";
import {qrTotal, shortQrName} from "./qrLinkHelpers.tsx";
import classes from "./QrCodes.module.scss";

const NO_CAMPAIGN = 'none';

interface CampaignGroup {
    key: string;
    campaign: QrCampaign | null;
    name: string;
    links: QrLink[];
}

const byPerformance = (a: QrLink, b: QrLink) =>
    qrTotal(b) - qrTotal(a) || b.orders_count - a.orders_count || b.created_at.localeCompare(a.created_at);

const QrCodes = () => {
    const {organizerId} = useParams();
    const [searchParams, setSearchParams] = useSearchParams();
    const qrLinksQuery = useGetQrLinks(organizerId!);
    const campaignsQuery = useGetQrCampaigns(organizerId!);
    const {data: organizer} = useGetOrganizer(organizerId!);
    const updateMutation = useUpdateQrLink();
    const deleteMutation = useDeleteQrLink();
    const deleteCampaignMutation = useDeleteQrCampaign();

    const [formTarget, setFormTarget] = useState<QrLink | 'new' | null>(null);
    const [newDefaultCampaign, setNewDefaultCampaign] = useState<IdParam | undefined>();
    const [statsTarget, setStatsTarget] = useState<QrLink | null>(null);
    const [campaignTarget, setCampaignTarget] = useState<QrCampaign | 'new' | null>(null);
    const [search, setSearch] = useState('');

    const qrLinks = qrLinksQuery.data;
    const campaigns = campaignsQuery.data ?? [];
    const currency = organizer?.currency || 'EUR';
    const selected = searchParams.get('campagne');

    const selectCampaign = (value: string | null) => {
        const next = new URLSearchParams(searchParams);
        if (!value || value === selected) {
            next.delete('campagne');
        } else {
            next.set('campagne', value);
        }
        setSearchParams(next, {replace: true});
    };

    const groups = useMemo<CampaignGroup[]>(() => {
        const all = qrLinks ?? [];
        const campaignGroups: CampaignGroup[] = campaigns.map(campaign => ({
            key: String(campaign.id),
            campaign,
            name: campaign.name,
            links: all.filter(qrLink => String(qrLink.qr_campaign_id ?? '') === String(campaign.id)).sort(byPerformance),
        }));
        const total = (group: CampaignGroup) => group.links.reduce((sum, qrLink) => sum + qrTotal(qrLink), 0);
        campaignGroups.sort((a, b) => total(b) - total(a) || b.links.length - a.links.length);

        const known = new Set(campaigns.map(campaign => String(campaign.id)));
        const orphans = all.filter(qrLink => !qrLink.qr_campaign_id || !known.has(String(qrLink.qr_campaign_id)));
        if (orphans.length) {
            campaignGroups.push({key: NO_CAMPAIGN, campaign: null, name: t`No campaign`, links: orphans.sort(byPerformance)});
        }

        return campaignGroups;
    }, [qrLinks, campaigns]);

    const visibleGroups = useMemo(() => {
        const query = search.trim().toLowerCase();
        return groups
            .filter(group => !selected || group.key === selected)
            .map(group => ({
                ...group,
                links: query
                    ? group.links.filter(qrLink => qrLink.name.toLowerCase().includes(query)
                        || (qrLink.event_title || '').toLowerCase().includes(query))
                    : group.links,
            }))
            .filter(group => !query || group.links.length > 0);
    }, [groups, selected, search]);

    const backgrounds = useMemo<QrPosterBackground[]>(() => {
        const unique = new Map<number, QrPosterBackground>();
        (qrLinks || []).forEach(({design}) => {
            if (design?.template === 'custom' && design.background_image_id && design.background_image_url) {
                unique.set(Number(design.background_image_id), {
                    id: Number(design.background_image_id),
                    url: design.background_image_url,
                    width: design.background_width,
                    height: design.background_height,
                });
            }
        });
        return [...unique.values()];
    }, [qrLinks]);

    const openCreate = (campaignId?: IdParam) => {
        setNewDefaultCampaign(campaignId ?? (selected && selected !== NO_CAMPAIGN ? selected : undefined));
        setFormTarget('new');
    };

    const toggleStatus = (qrLink: QrLink) => {
        updateMutation.mutate({
            organizerId: organizerId!,
            qrLinkId: qrLink.id,
            qrLink: {
                name: qrLink.name,
                destination_type: qrLink.destination_type,
                destination_url: qrLink.destination_url,
                app_store_url: qrLink.app_store_url,
                play_store_url: qrLink.play_store_url,
                event_id: qrLink.event_id,
                qr_campaign_id: qrLink.qr_campaign_id,
                title: qrLink.title,
                description: qrLink.description,
                social_links: qrLink.social_links,
                design: qrLink.design,
                status: qrLink.status === 'ACTIVE' ? 'PAUSED' : 'ACTIVE',
            },
        }, {
            onSuccess: () => showSuccess(qrLink.status === 'ACTIVE' ? t`QR code paused` : t`QR code reactivated`),
            onError: () => showError(t`Unable to update the QR code`),
        });
    };

    const removeQrLink = (qrLink: QrLink) => {
        confirmationDialog(t`Delete this QR code? Printed copies will stop working and its statistics will be lost.`, () => {
            deleteMutation.mutate({organizerId: organizerId!, qrLinkId: qrLink.id}, {
                onSuccess: () => showSuccess(t`QR code deleted`),
                onError: () => showError(t`Unable to delete the QR code`),
            });
        });
    };

    const removeCampaign = (campaign: QrCampaign) => {
        confirmationDialog(t`Delete the campaign "${campaign.name}"? Its QR codes are kept and keep working, they just move to "No campaign".`, () => {
            deleteCampaignMutation.mutate({organizerId: organizerId!, campaignId: campaign.id}, {
                onSuccess: () => {
                    selectCampaign(null);
                    showSuccess(t`Campaign deleted`);
                },
                onError: () => showError(t`Unable to delete the campaign`),
            });
        });
    };

    const isLoading = !qrLinks && !qrLinksQuery.isError;
    const hasLinks = (qrLinks?.length ?? 0) > 0;
    const isEmpty = !!qrLinks && !hasLinks && campaigns.length === 0;
    const allAtZero = hasLinks && (qrLinks ?? []).every(qrLink => qrTotal(qrLink) === 0);
    const campaignNameOf = (qrLink: QrLink) =>
        campaigns.find(campaign => String(campaign.id) === String(qrLink.qr_campaign_id))?.name;

    return (
        <PageBody>
            <div className={classes.page}>
                <header className={classes.pageHeader}>
                    <div>
                        <h1 className={classes.pageTitle}>{t`QR codes`}</h1>
                        <p className={classes.pageIntro}>
                            {t`One QR code per medium, grouped by campaign. You can change where it leads without reprinting it.`}
                        </p>
                    </div>
                    {!isEmpty && (
                        <div className={classes.pageActions}>
                            <Button variant="default" leftSection={<IconFolderPlus size={16}/>}
                                    onClick={() => setCampaignTarget('new')} disabled={qrLinksQuery.isError}>
                                {t`New campaign`}
                            </Button>
                            <Button leftSection={<IconPlus size={16}/>} onClick={() => openCreate()}
                                    disabled={qrLinksQuery.isError}>
                                {t`New QR code`}
                            </Button>
                        </div>
                    )}
                </header>

                {qrLinksQuery.isError && (
                    <Alert color="red" variant="light">
                        {t`This organizer could not be found. Choose your organizer from the menu, then open QR codes again.`}
                    </Alert>
                )}

                {isLoading && (
                    <>
                        <div className={classes.campaignStrip}>
                            {[...Array(3)].map((_, index) => <Skeleton key={index} height={150} radius="lg"/>)}
                        </div>
                        {[...Array(3)].map((_, index) => <Skeleton key={index} height={64} radius="md" mb={8}/>)}
                    </>
                )}

                {isEmpty && (
                    <div className={classes.welcome}>
                        <span className={classes.welcomeIcon}><IconQrcode size={32} stroke={1.5}/></span>
                        <h2 className={classes.welcomeTitle}>{t`Create your first QR code`}</h2>
                        <ol className={classes.welcomeSteps}>
                            <li>{t`Name the medium where it will appear: poster, flyer, story...`}</li>
                            <li>{t`Choose what it opens: your link page, an event, a website.`}</li>
                            <li>{t`Download it and print it, or share its short link.`}</li>
                        </ol>
                        <Button size="md" leftSection={<IconPlus size={18}/>} onClick={() => openCreate()}>
                            {t`Create a QR code`}
                        </Button>
                    </div>
                )}

                {qrLinks && !isEmpty && (
                    <>
                        <section aria-label={t`Campaigns`}>
                            <div className={classes.campaignStrip}>
                                {groups.map(group => (
                                    <QrCampaignCard
                                        key={group.key}
                                        name={group.name}
                                        links={group.links}
                                        isMuted={!group.campaign}
                                        isActive={selected === group.key}
                                        onSelect={() => selectCampaign(group.key)}
                                        onRename={group.campaign ? () => setCampaignTarget(group.campaign) : undefined}
                                        onDelete={group.campaign ? () => removeCampaign(group.campaign!) : undefined}
                                    />
                                ))}
                                <button type="button" className={classes.campaignCardAdd}
                                        onClick={() => setCampaignTarget('new')}>
                                    <IconFolderPlus size={20}/>
                                    {t`New campaign`}
                                </button>
                            </div>
                        </section>

                        {allAtZero && (
                            <div className={classes.zeroTip}>
                                <IconBulb size={20} className={classes.zeroTipIcon}/>
                                <div>
                                    <Text fw={600} size="sm">{t`No scans yet`}</Text>
                                    <Text size="sm" c="dimmed">
                                        {t`To get your first ones, download a QR code (⋮ menu, then PNG or visual) and put it on your poster, or copy its short link into your bio, a story or a WhatsApp message.`}
                                    </Text>
                                </div>
                            </div>
                        )}

                        {hasLinks && (qrLinks.length > 6 || search) && (
                            <TextInput className={classes.search} leftSection={<IconSearch size={16}/>}
                                       placeholder={t`Search a QR code or an event…`} value={search}
                                       onChange={(event) => setSearch(event.currentTarget.value)}/>
                        )}

                        {selected && (
                            <Button variant="subtle" size="compact-sm" leftSection={<IconX size={14}/>} mb="sm"
                                    onClick={() => selectCampaign(null)}>
                                {t`Show all campaigns`}
                            </Button>
                        )}

                        {visibleGroups.length === 0 && search && (
                            <div className={classes.emptyState}>
                                <Text fw={600}>{t`No QR code matches these filters`}</Text>
                            </div>
                        )}

                        {visibleGroups.map(group => (
                            <section key={group.key} className={classes.group}>
                                <div className={classes.groupHeader}>
                                    <h2 className={classes.groupTitle}>{group.name}</h2>
                                    <span className={classes.groupCount}>{formatNumber(group.links.length)}</span>
                                </div>

                                {group.links.length > 0 ? (
                                    <div className={classes.rows}>
                                        {group.links.map(qrLink => (
                                            <QrLinkRow
                                                key={qrLink.id}
                                                qrLink={qrLink}
                                                campaignName={group.campaign?.name}
                                                onOpen={() => setStatsTarget(qrLink)}
                                                onEdit={() => setFormTarget(qrLink)}
                                                onToggleStatus={() => toggleStatus(qrLink)}
                                                onDelete={() => removeQrLink(qrLink)}
                                            />
                                        ))}
                                    </div>
                                ) : (
                                    <div className={classes.emptyState}>
                                        <Text size="sm" c="dimmed" mb="sm">
                                            {t`Create the QR codes of this campaign: poster, flyer, story...`}
                                        </Text>
                                        <Button size="xs" variant="light" leftSection={<IconPlus size={14}/>}
                                                onClick={() => openCreate(group.campaign?.id)}>
                                            {t`New QR code`}
                                        </Button>
                                    </div>
                                )}
                            </section>
                        ))}
                    </>
                )}
            </div>

            {formTarget && (
                <QrLinkFormModal
                    qrLink={formTarget === 'new' ? undefined : formTarget}
                    campaigns={campaigns}
                    defaultCampaignId={newDefaultCampaign}
                    backgrounds={backgrounds}
                    organizerName={organizer?.name}
                    organizerLogoUrl={organizer?.images?.find(image => image.type === 'ORGANIZER_LOGO')?.url}
                    onClose={() => setFormTarget(null)}
                />
            )}

            {campaignTarget && (
                <QrCampaignModal
                    campaign={campaignTarget === 'new' ? undefined : campaignTarget}
                    onClose={() => setCampaignTarget(null)}
                    onSaved={(campaign) => {
                        if (campaignTarget === 'new') {
                            selectCampaign(String(campaign.id));
                        }
                    }}
                />
            )}

            <QrLinkStatsDrawer
                qrLink={statsTarget ? (qrLinks?.find(qrLink => qrLink.id === statsTarget.id) ?? statsTarget) : null}
                shortName={statsTarget ? shortQrName(statsTarget.name, campaignNameOf(statsTarget)) : undefined}
                currency={currency}
                onClose={() => setStatsTarget(null)}
                onEdit={() => {
                    if (statsTarget) {
                        setFormTarget(qrLinks?.find(qrLink => qrLink.id === statsTarget.id) ?? statsTarget);
                        setStatsTarget(null);
                    }
                }}
            />
        </PageBody>
    );
};

export default QrCodes;
