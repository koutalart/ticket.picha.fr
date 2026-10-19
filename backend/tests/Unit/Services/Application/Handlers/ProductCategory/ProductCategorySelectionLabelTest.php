<?php

namespace Tests\Unit\Services\Application\Handlers\ProductCategory;

use HiEvents\DomainObjects\ProductCategoryDomainObject;
use HiEvents\Repository\Interfaces\ProductCategoryRepositoryInterface;
use HiEvents\Services\Application\Handlers\ProductCategory\CreateProductCategoryHandler;
use HiEvents\Services\Application\Handlers\ProductCategory\DTO\UpsertProductCategoryDTO;
use HiEvents\Services\Application\Handlers\ProductCategory\EditProductCategoryHandler;
use HiEvents\Services\Domain\ProductCategory\CreateProductCategoryService;
use HiEvents\Services\Infrastructure\HtmlPurifier\HtmlPurifierService;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

class ProductCategorySelectionLabelTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private function purifier(): HtmlPurifierService
    {
        $purifier = Mockery::mock(HtmlPurifierService::class);
        $purifier->shouldReceive('purify')->andReturnUsing(fn ($value) => $value);

        return $purifier;
    }

    private function dto(?string $label, ?int $categoryId = null): UpsertProductCategoryDTO
    {
        return new UpsertProductCategoryDTO(
            name: 'Candidats',
            description: null,
            is_hidden: false,
            event_id: 5,
            no_products_message: null,
            product_category_id: $categoryId,
            selection_label: $label,
        );
    }

    public function test_create_persists_trimmed_selection_label(): void
    {
        $service = Mockery::mock(CreateProductCategoryService::class);
        $service->shouldReceive('createCategory')
            ->once()
            ->withArgs(fn (ProductCategoryDomainObject $category) => $category->getSelectionLabel() === 'Je suis candidat')
            ->andReturnUsing(fn (ProductCategoryDomainObject $category) => $category);

        $result = (new CreateProductCategoryHandler($service, $this->purifier()))->handle($this->dto('  Je suis <b>candidat</b> '));

        $this->assertSame('Je suis candidat', $result->getSelectionLabel());
    }

    public function test_create_stores_null_for_blank_selection_label(): void
    {
        $service = Mockery::mock(CreateProductCategoryService::class);
        $service->shouldReceive('createCategory')
            ->once()
            ->withArgs(fn (ProductCategoryDomainObject $category) => $category->getSelectionLabel() === null)
            ->andReturnUsing(fn (ProductCategoryDomainObject $category) => $category);

        (new CreateProductCategoryHandler($service, $this->purifier()))->handle($this->dto('   '));
    }

    public function test_edit_persists_selection_label(): void
    {
        $repository = Mockery::mock(ProductCategoryRepositoryInterface::class);
        $repository->shouldReceive('updateWhere')
            ->once()
            ->withArgs(fn (array $attributes, array $where) => $attributes['selection_label'] === 'Je suis exposant'
                && $where === ['id' => 9, 'event_id' => 5])
            ->andReturn(1);
        $repository->shouldReceive('findFirstWhere')
            ->once()
            ->andReturn((new ProductCategoryDomainObject)->setSelectionLabel('Je suis exposant'));

        $result = (new EditProductCategoryHandler($repository, $this->purifier()))->handle($this->dto('Je suis exposant', 9));

        $this->assertSame('Je suis exposant', $result->getSelectionLabel());
    }
}
