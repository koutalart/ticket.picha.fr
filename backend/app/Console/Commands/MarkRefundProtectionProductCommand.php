<?php

declare(strict_types=1);

namespace HiEvents\Console\Commands;

use HiEvents\DomainObjects\Generated\ProductDomainObjectAbstract;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\Repository\Interfaces\ProductRepositoryInterface;
use Illuminate\Console\Command;

/**
 * Marks a product as a cancellation protection (same as the box of the product form), with a safety check on its
 * title so that a wrong id on another database changes nothing.
 * Example: php artisan live-chat:refund-protection 33 --title-contains="sérénité"
 */
class MarkRefundProtectionProductCommand extends Command
{
    protected $signature = 'live-chat:refund-protection
        {product_id : Id of the product}
        {--title-contains= : Required: part of the product title, checked case-insensitively}
        {--off : Remove the mark instead}';

    protected $description = 'Mark a product as a cancellation protection, refundable from the web chat';

    public function handle(ProductRepositoryInterface $productRepository): int
    {
        $expected = trim((string) $this->option('title-contains'));
        if ($expected === '') {
            $this->error('Give --title-contains, for example --title-contains="sérénité".');

            return self::FAILURE;
        }

        /** @var ProductDomainObject|null $product */
        $product = $productRepository->findFirstWhere([
            ProductDomainObjectAbstract::ID => (int) $this->argument('product_id'),
        ]);

        if ($product === null) {
            $this->error('Product not found: nothing changed.');

            return self::FAILURE;
        }

        if (! str_contains(mb_strtolower($product->getTitle()), mb_strtolower($expected))) {
            $this->error(sprintf('The title "%s" does not contain "%s": nothing changed.', $product->getTitle(), $expected));

            return self::FAILURE;
        }

        $enabled = ! $this->option('off');
        $productRepository->updateFromArray($product->getId(), [
            ProductDomainObjectAbstract::IS_REFUND_PROTECTION => $enabled,
        ]);

        $this->info(sprintf(
            'Product %d "%s" (event %d): cancellation protection %s.',
            $product->getId(),
            $product->getTitle(),
            $product->getEventId(),
            $enabled ? 'on' : 'off',
        ));

        return self::SUCCESS;
    }
}
