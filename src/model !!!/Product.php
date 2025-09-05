<?php

declare(strict_types=1);

namespace App\model;

final class Product
{
    public int $id;
    public int $category_id;
    public string $category_name;
    public string $nom;
    public string $slug;
    public ?string $description_courte;
    public ?string $description_longue;
    public float $prix;
    public ?float $prix_promo;
    public bool $is_active;
    public string $created_at;
    public string $updated_at;

    /** @var array<int, array{image_url:string,alt_text:?string,is_primary:int,ordre:int}> */
    public array $images = [];

    /** @var array<int, array{id:int,taille:?string,couleur:?string,sku:?string,stock:int,is_active:int,prix_effectif:float,image_url:?string}> */
    public array $variants = [];

    public ?float $note_moyenne = null;
    public int $nb_avis = 0;

    public static function fromRow(array $r): self
    {
        $p = new self();
        $p->id                   = (int)$r['id'];
        $p->category_id          = (int)$r['category_id'];
        $p->category_name        = (string)$r['category_name'];
        $p->nom                  = (string)$r['nom'];
        $p->slug                 = (string)$r['slug'];
        $p->description_courte   = $r['description_courte'] ?? null;
        $p->description_longue   = $r['description_longue'] ?? null;
        $p->prix                 = (float)$r['prix'];
        $p->prix_promo           = isset($r['prix_promo']) ? (float)$r['prix_promo'] : null;
        $p->is_active            = (int)$r['is_active'] === 1;
        $p->created_at           = (string)$r['created_at'];
        $p->updated_at           = (string)$r['updated_at'];
        return $p;
    }

    public function toArray(): array
    {
        return [
            'product'  => [
                'id'                 => $this->id,
                'category_id'        => $this->category_id,
                'category_name'      => $this->category_name,
                'nom'                => $this->nom,
                'slug'               => $this->slug,
                'description_courte' => $this->description_courte,
                'description_longue' => $this->description_longue,
                'prix'               => $this->prix,
                'prix_promo'         => $this->prix_promo,
                'is_active'          => $this->is_active,
                'created_at'         => $this->created_at,
                'updated_at'         => $this->updated_at,
            ],
            'images'   => $this->images,
            'variants' => $this->variants,
            'rating'   => [
                'note_moyenne' => $this->note_moyenne,
                'nb_avis'      => $this->nb_avis,
            ],
        ];
    }
}
