import {useMemo, useState} from "react";
import {useParams, useSearchParams} from "react-router";
import {t} from "@lingui/macro";
import {ActionIcon, Alert, Button, Menu, SegmentedControl, Select, Skeleton, Text, TextInput} from "@mantine/core";
import {IconDotsVertical, IconFolder, IconFolderPlus, IconPencil, IconPlus, IconQrcode, IconSearch, IconTrash} from "@tabler/icons-react";
import {IdParam, QrCampaign, QrLink} from "../../../../types.ts";
import {useGetQrLinks} from "../../../../queries/useGetQrLinks.ts";
import {useGetQrCampaigns} from "../../../../queries/useGetQrCampaigns.ts";
import {useGetOrganizer} from "../../../../queries/useGetOrganizer.ts";
import {useUpdateQrLink} from "../../../../mutations/useUpdateQrLink.ts";
import {useDeleteQrLink} from "../../../../mutations/useDeleteQrLink.ts";
import {useDeleteQrCampaign} from "../../../../mutations/useDeleteQrCampaign.ts";
import {showError, showSuccess} from "../../../../utilites/notifications.tsx";
import {confirmationDialog} from "../../../../utilites/confirmationDialog.tsx";
import {formatCurrency} from "../../../../utilites/currency.ts";
import {formatNumber} from "../../../../utilites/helpers.ts";
import {PageBody} from "../../../common/PageBody";
import {QrLinkRow} from "./QrLinkRow.tsx";
import {QrLinkFormModal, QrPosterBackground} from "./QrLinkFormModal.tsx";
import {QrLinkStatsDrawer} from "./QrLinkStatsDrawer.tsx";
import {QrCampaignModal} from "./QrCampaignModal.tsx";
import classes from "./QrCodes.module.scss";

const ALL = 'all';
const NO_CAMPAIGN = 'none';

type StatusFilter = 'all' | 'ACTIVE' | 'PAUSED';
type SortKey = 'recent' | 'scans' | 'orders' | 'name';

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
    const [statsTarget, setStatsTarget] = useState<QrLink | null>(null);
    const [campaignTarget, setCampaignTarget] = useState<QrCampaign | 'new' | null>(null);
    const [search, setSearch] = useState('');
    const [status, setStatus] = useState<StatusFilter>('all');
    const [sort, setSort] = useState<SortKey>('recent');

    const qrLinks = qrLinksQuery.data;
    const campaigns = campaignsQuery.data ?? [];
    const currency = organizer?.currency || 'EUR';
    const selected = searchParams.get('campagne') || ALL;
    const selectedCampaign = campaigns.find(campaign => String(campaign.id) === selected);
    const showRevenue = (qrLinks ?? []).some(qrLink => qrLink.revenue_gross > 0);

    const selectCampaign = (value: string) => {
        const next = new URLSearchParams(searchParams);
        if (value === ALL) {
            next.delete('campagne');
        } else {
            next.set('campagne', value);
        }
        setSearchParams(next, {replace: true});
    };

    const countFor = (campaignId: IdParam | null) =>
        (qrLinks ?? []).filter(qrLink => String(qrLink.qr_campaign_id ?? '') === String(campaignId ?? '')).length;

    const inScope = useMemo(() => (qrLinks ?? []).filter(qrLink => {
        if (selected === ALL) return true;
        if (selected === NO_CAMPAIGN) return !qrLink.qr_campaign_id;
        return String(qrLink.qr_campaign_id) === selected;
    }), [qrLinks, selected]);

    const visible = useMemo(() => {
        const query = search.trim().toLowerCase();
        const filtered = inScope.filter(qrLink =>
            (status === 'all' || qrLink.status === status)
            && (!query || qrLink.name.toLowerCase().includes(query) || (qrLink.event_title || '').toLowerCase().includes(query))
        );

        return [...filtered].sort((a, b) => {
            switch (sort) {
                case 'scans':
                    return b.scans_count - a.scans_count;
                case 'orders':
                    return b.orders_count - a.orders_count || b.revenue_gross - a.revenue_gross;
                case 'name':
                    return a.name.localeCompare(b.name);
                default:
                    return b.created_at.localeCompare(a.created_at);
            }
        });
    }, [inScope, search, status, sort]);

    const totals = useMemo(() => inScope.reduce((sum, qrLink) => ({
        scans: sum.scans + qrLink.scans_count,
        unique: sum.unique + qrLink.unique_scans_count,
        orders: sum.orders + qrLink.orders_count,
        revenue: sum.revenue + qrLink.revenue_gross,
    }), {scans: 0, unique: 0, orders: 0, revenue: 0}), [inScope]);

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

    const toggleStatus = (qrLink: QrLink) => {
        updateMutation.mutate({
            organizerId: organizerId!,
            qrLinkId: qrLink.id,
            qrLink: {
                name: qrLink.name,
                destination_type: qrLink.destination_type,
                destination_url: qrLink.destination_url,
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
                    selectCampaign(ALL);
                    showSuccess(t`Campaign deleted`);
                },
                onError: () => showError(t`Unable to delete the campaign`),
            });
        });
    };

    const scopeTitle = selected === ALL ? t`All QR codes`
        : selected === NO_CAMPAIGN ? t`No campaign`
            : selectedCampaign?.name ?? t`Campaign`;

    const scopeOptions = [
        {value: ALL, label: `${t`All QR codes`} (${qrLinks?.length ?? 0})`},
        ...campaigns.map(campaign => ({value: String(campaign.id), label: `${campaign.name} (${countFor(campaign.id)})`})),
        {value: NO_CAMPAIGN, label: `${t`No campaign`} (${countFor(null)})`},
    ];

    const isLoading = !qrLinks && !qrLinksQuery.isError;

    return (
        <PageBody>
            <div className={classes.page}>
                <header className={classes.pageHeader}>
                    <div>
                        <h1 className={classes.pageTitle}>{t`QR codes`}</h1>
                        <p className={classes.pageIntro}>
                            {t`One QR code per medium (poster, flyer, screen...), grouped by campaign. Change where a QR code leads at any time without reprinting it.`}
                        </p>
                    </div>
                    <div className={classes.pageActions}>
                        <Button variant="default" leftSection={<IconFolderPlus size={16}/>}
                                onClick={() => setCampaignTarget('new')} disabled={qrLinksQuery.isError}>
                            {t`New campaign`}
                        </Button>
                        <Button leftSection={<IconPlus size={16}/>} onClick={() => setFormTarget('new')}
                                disabled={qrLinksQuery.isError}>
                            {t`New QR code`}
                        </Button>
                    </div>
                </header>

                {qrLinksQuery.isError && (
                    <Alert color="red" variant="light">
                        {t`This organizer could not be found. Choose your organizer from the menu, then open QR codes again.`}
                    </Alert>
                )}

                {!qrLinksQuery.isError && (
                    <div className={classes.layout}>
                        <nav className={classes.campaigns} aria-label={t`Campaigns`}>
                            <button type="button" className={classes.campaignItem} data-active={selected === ALL}
                                    onClick={() => selectCampaign(ALL)}>
                                <IconQrcode size={16}/>
                                <span>{t`All QR codes`}</span>
                                <span className={classes.campaignCount}>{qrLinks?.length ?? 0}</span>
                            </button>

                            <div className={classes.campaignsLabel}>{t`Campaigns`}</div>
                            {campaigns.map(campaign => (
                                <button type="button" key={campaign.id} className={classes.campaignItem}
                                        data-active={selected === String(campaign.id)}
                                        onClick={() => selectCampaign(String(campaign.id))}>
                                    <IconFolder size={16}/>
                                    <span className={classes.campaignName}>{campaign.name}</span>
                                    <span className={classes.campaignCount}>{countFor(campaign.id)}</span>
                                </button>
                            ))}
                            {campaigns.length === 0 && (
                                <Text size="xs" c="dimmed" px={10} pb={6}>
                                    {t`Create a campaign to group the QR codes of one operation.`}
                                </Text>
                            )}
                            <button type="button" className={classes.campaignItem} data-active={selected === NO_CAMPAIGN}
                                    onClick={() => selectCampaign(NO_CAMPAIGN)}>
                                <IconFolder size={16} opacity={0.4}/>
                                <span>{t`No campaign`}</span>
                                <span className={classes.campaignCount}>{countFor(null)}</span>
                            </button>
                            <button type="button" className={classes.campaignAdd} onClick={() => setCampaignTarget('new')}>
                                <IconPlus size={14}/> {t`New campaign`}
                            </button>
                        </nav>

                        <section className={classes.content}>
                            <Select className={classes.scopeSelect} data={scopeOptions} value={selected}
                                    onChange={(value) => selectCampaign(value || ALL)} allowDeselect={false}
                                    leftSection={<IconFolder size={16}/>} aria-label={t`Campaign`}/>

                            <div className={classes.scopeHeader}>
                                <h2 className={classes.scopeTitle}>{scopeTitle}</h2>
                                {selectedCampaign && (
                                    <Menu position="bottom-end" withinPortal shadow="md">
                                        <Menu.Target>
                                            <ActionIcon variant="subtle" color="gray" aria-label={t`Campaign actions`}>
                                                <IconDotsVertical size={18}/>
                                            </ActionIcon>
                                        </Menu.Target>
                                        <Menu.Dropdown>
                                            <Menu.Item leftSection={<IconPencil size={14}/>}
                                                       onClick={() => setCampaignTarget(selectedCampaign)}>
                                                {t`Rename`}
                                            </Menu.Item>
                                            <Menu.Item color="red" leftSection={<IconTrash size={14}/>}
                                                       onClick={() => removeCampaign(selectedCampaign)}>
                                                {t`Delete the campaign`}
                                            </Menu.Item>
                                        </Menu.Dropdown>
                                    </Menu>
                                )}
                            </div>

                            <div className={classes.totals}>
                                <div><strong>{formatNumber(inScope.length)}</strong><span>{t`QR codes`}</span></div>
                                <div><strong>{formatNumber(totals.scans)}</strong><span>{t`Scans`}</span></div>
                                <div><strong>{formatNumber(totals.unique)}</strong><span>{t`Unique visitors`}</span></div>
                                <div>
                                    <strong>{formatNumber(totals.orders)}</strong>
                                    <span>{t`Orders`}</span>
                                </div>
                                {showRevenue && (
                                    <div><strong>{formatCurrency(totals.revenue, currency)}</strong><span>{t`Revenue`}</span></div>
                                )}
                            </div>

                            <div className={classes.toolbar}>
                                <TextInput className={classes.toolbarSearch} leftSection={<IconSearch size={16}/>}
                                           placeholder={t`Search a QR code or an event…`} value={search}
                                           onChange={(event) => setSearch(event.currentTarget.value)}/>
                                <SegmentedControl size="sm" value={status} onChange={(value) => setStatus(value as StatusFilter)}
                                                  data={[
                                                      {value: 'all', label: t({message: 'All', context: 'qr status filter'})},
                                                      {value: 'ACTIVE', label: t`Active`},
                                                      {value: 'PAUSED', label: t`Paused`},
                                                  ]}/>
                                <Select className={classes.toolbarSort} value={sort} allowDeselect={false}
                                        onChange={(value) => setSort((value as SortKey) || 'recent')}
                                        data={[
                                            {value: 'recent', label: t`Most recent`},
                                            {value: 'scans', label: t`Most scanned`},
                                            {value: 'orders', label: showRevenue ? t`Most sales` : t`Most orders`},
                                            {value: 'name', label: t`Name (A-Z)`},
                                        ]}
                                        aria-label={t`Sort`}/>
                            </div>

                            {isLoading && [...Array(3)].map((_, index) => <Skeleton key={index} height={72} radius="md" mb={8}/>)}

                            {qrLinks && visible.length > 0 && (
                                <div className={classes.rows}>
                                    {visible.map(qrLink => (
                                        <QrLinkRow
                                            key={qrLink.id}
                                            qrLink={qrLink}
                                            currency={currency}
                                            showRevenue={showRevenue}
                                            campaignName={selected === ALL
                                                ? campaigns.find(campaign => String(campaign.id) === String(qrLink.qr_campaign_id))?.name
                                                : undefined}
                                            onEdit={() => setFormTarget(qrLink)}
                                            onStats={() => setStatsTarget(qrLink)}
                                            onToggleStatus={() => toggleStatus(qrLink)}
                                            onDelete={() => removeQrLink(qrLink)}
                                        />
                                    ))}
                                </div>
                            )}

                            {qrLinks && visible.length === 0 && (
                                <div className={classes.emptyState}>
                                    <IconQrcode size={36} stroke={1.5}/>
                                    <Text fw={600} mt="sm">
                                        {inScope.length === 0 ? t`No QR codes here yet` : t`No QR code matches these filters`}
                                    </Text>
                                    {inScope.length === 0 && (
                                        <>
                                            <Text size="sm" c="dimmed" mb="md">
                                                {selectedCampaign
                                                    ? t`Create the QR codes of this campaign: poster, flyer, story...`
                                                    : t`Start with the poster for your next event.`}
                                            </Text>
                                            <Button leftSection={<IconPlus size={16}/>} onClick={() => setFormTarget('new')}>
                                                {t`New QR code`}
                                            </Button>
                                        </>
                                    )}
                                </div>
                            )}
                        </section>
                    </div>
                )}

            </div>

            {formTarget && (
                <QrLinkFormModal
                    qrLink={formTarget === 'new' ? undefined : formTarget}
                    campaigns={campaigns}
                    defaultCampaignId={selectedCampaign?.id}
                    backgrounds={backgrounds}
                    organizerName={organizer?.name}
                    onClose={() => setFormTarget(null)}
                />
            )}

            {campaignTarget && (
                <QrCampaignModal
                    campaign={campaignTarget === 'new' ? undefined : campaignTarget}
                    onClose={() => setCampaignTarget(null)}
                    onSaved={(campaign) => selectCampaign(String(campaign.id))}
                />
            )}

            <QrLinkStatsDrawer qrLink={statsTarget} currency={currency} onClose={() => setStatsTarget(null)}/>
        </PageBody>
    );
};

export default QrCodes;
