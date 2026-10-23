<?php

namespace HiEvents\Services\Domain\Report\Factory;

use HiEvents\DomainObjects\Enums\ReportTypes;
use HiEvents\Services\Domain\Report\AbstractReportService;
use HiEvents\Services\Domain\Report\Reports\DailySalesReport;
use HiEvents\Services\Domain\Report\Reports\ProductSalesReport;
use HiEvents\Services\Domain\Report\Reports\PromoCodesReport;
use HiEvents\Services\Domain\Report\Reports\SalesBySourceReport;
use Illuminate\Support\Facades\App;

class ReportServiceFactory
{
    public function create(ReportTypes $reportType): AbstractReportService
    {
        return match ($reportType) {
            ReportTypes::PRODUCT_SALES => App::make(ProductSalesReport::class),
            ReportTypes::DAILY_SALES_REPORT => App::make(DailySalesReport::class),
            ReportTypes::PROMO_CODES_REPORT => App::make(PromoCodesReport::class),
            ReportTypes::SALES_BY_SOURCE_REPORT => App::make(SalesBySourceReport::class),
        };
    }
}
