<?php
// Salon Mayotte Recrute (événement 13) : choix du parcours par boutons (« Choisir d'abord une catégorie »). Relançable.

use HiEvents\Services\Application\Handlers\EventSettings\DTO\PartialUpdateEventSettingsDTO;
use HiEvents\Services\Application\Handlers\EventSettings\PartialUpdateEventSettingsHandler;
use HiEvents\Services\Application\Handlers\ProductCategory\DTO\UpsertProductCategoryDTO;
use HiEvents\Services\Application\Handlers\ProductCategory\EditProductCategoryHandler;
use Illuminate\Support\Facades\DB;

if (DB::table('events')->where('id', 13)->where('account_id', 7)->value('title') !== 'Salon Mayotte Recrute 2026') {
    throw new RuntimeException('Événement inattendu : rien n\'est modifié');
}
auth()->setUser(\HiEvents\Models\User::findOrFail(11));

foreach (['Candidats' => 'S\'inscrire en tant que Candidat', 'Exposants' => 'S\'inscrire en tant qu\'Exposant'] as $nom => $bouton) {
    $c = DB::table('product_categories')->where('event_id', 13)->where('name', $nom)->whereNull('deleted_at')->first();
    if (!$c) throw new RuntimeException('Catégorie manquante : '.$nom);
    app(EditProductCategoryHandler::class)->handle(new UpsertProductCategoryDTO(
        name: $nom,
        description: $c->description,
        is_hidden: false,
        event_id: 13,
        no_products_message: $c->no_products_message,
        product_category_id: $c->id,
        selection_label: $bouton,
    ));
}
app(PartialUpdateEventSettingsHandler::class)->handle(new PartialUpdateEventSettingsDTO(
    account_id: 7, event_id: 13, settings: ['choose_category_first' => true],
));

echo 'Choisir d\'abord une catégorie : '.(DB::table('event_settings')->where('event_id', 13)->value('choose_category_first') ? 'oui' : 'NON')."\n";
echo 'Boutons : '.DB::table('product_categories')->where('event_id', 13)->whereNull('deleted_at')->orderBy('id')->pluck('selection_label')->implode(' | ')."\n";
