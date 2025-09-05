<?php

declare(strict_types=1);

namespace App\repository;

use PDO;

final class OrderRepository
{
    public function __construct(private PDO $pdo) {}

    public function getPromo(string $code): ?array
    {
        $st = $this->pdo->prepare("SELECT type_code, valeur, is_active FROM codes_promo WHERE code = :c");
        $st->execute([':c' => $code]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function createOrder(int $uid, int $cartId, array $shipping, array $billing, float $total): int
    {
        $ins = $this->pdo->prepare("
            INSERT INTO commandes (
              id_utilisateur, id_panier, statut, total, devise,
              shipping_prenom, shipping_nom, shipping_num_tel, shipping_adresse1, shipping_adresse2, shipping_code_postal, shipping_ville, shipping_pays,
              billing_prenom,  billing_nom,  billing_num_tel,  billing_adresse1,  billing_adresse2,  billing_code_postal,  billing_ville,  billing_pays
            ) VALUES (
              :uid, :pid, 'en attente', :total, 'EUR',
              :spn, :snm, :stel, :sa1, :sa2, :scp, :svl, :spy,
              :bpn, :bnm, :btel, :ba1, :ba2, :bcp, :bvl, :bpy
            )
        ");
        $ins->execute([
            ':uid'  => $uid,
            ':pid'  => $cartId,
            ':total' => $total,
            ':spn'  => $shipping['prenom'],
            ':snm'  => $shipping['nom'],
            ':stel' => $shipping['num_tel'],
            ':sa1'  => $shipping['adresse1'],
            ':sa2'  => $shipping['adresse2'],
            ':scp'  => $shipping['code_postal'],
            ':svl'  => $shipping['ville'],
            ':spy'  => $shipping['pays'],
            ':bpn'  => $billing['prenom'],
            ':bnm'  => $billing['nom'],
            ':btel' => $billing['num_tel'],
            ':ba1'  => $billing['adresse1'],
            ':ba2'  => $billing['adresse2'],
            ':bcp'  => $billing['code_postal'],
            ':bvl'  => $billing['ville'],
            ':bpy'  => $billing['pays'],
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function insertOrderLine(
        int $orderId,
        int $productId,
        int $variationId,
        string $productName,
        ?string $variationLabel,
        float $unitPrice,
        int $qty,
        float $lineTotal
    ): void {
        $st = $this->pdo->prepare("
            INSERT INTO commande_articles (
              id_commande, id_produit, id_variation, nom_produit, variation, prix_unitaire, quantite, total_ligne
            ) VALUES (
              :cid, :pid, :vid, :pname, :var, :pu, :q, :tl
            )
        ");
        $st->execute([
            ':cid'   => $orderId,
            ':pid'   => $productId,
            ':vid'   => $variationId,
            ':pname' => $productName,
            ':var'   => $variationLabel,
            ':pu'    => $unitPrice,
            ':q'     => $qty,
            ':tl'    => $lineTotal,
        ]);
    }

    public function decrementStock(int $variationId, int $qty): void
    {
        $this->pdo->prepare("UPDATE variations_produits SET stock = stock - :q WHERE id_variation = :vid")
            ->execute([':q' => $qty, ':vid' => $variationId]);
    }

    public function insertPendingPayment(int $orderId, float $amount): void
    {
        $this->pdo->prepare("
            INSERT INTO paiements (id_commande, montant, mode_paiement, statut, fournisseur)
            VALUES (:cid, :m, 'CB', 'en attente', 'mock')
        ")->execute([':cid' => $orderId, ':m' => $amount]);
    }

    public function listForUser(int $uid): array
    {
        $st = $this->pdo->prepare("
            SELECT id_commande AS id, statut, total, devise, created_at
            FROM commandes
            WHERE id_utilisateur = :uid
            ORDER BY id_commande DESC
        ");
        $st->execute([':uid' => $uid]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getOrder(int $id): ?array
    {
        $st = $this->pdo->prepare("SELECT * FROM commandes WHERE id_commande = :id");
        $st->execute([':id' => $id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function getOrderForUpdate(int $id): ?array
    {
        $st = $this->pdo->prepare("SELECT * FROM commandes WHERE id_commande = :id FOR UPDATE");
        $st->execute([':id' => $id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function getOrderLines(int $id): array
    {
        $st = $this->pdo->prepare("
            SELECT id_commande_article AS id, id_produit, id_variation, nom_produit, variation, prix_unitaire, quantite, total_ligne
            FROM commande_articles WHERE id_commande = :id
        ");
        $st->execute([':id' => $id]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getPayments(int $id): array
    {
        $pay = $this->pdo->prepare("
            SELECT id_paiement, montant, mode_paiement, statut, fournisseur, transaction_ref, created_at
            FROM paiements WHERE id_commande = :id
        ");
        $pay->execute([':id' => $id]);
        return $pay->fetchAll(PDO::FETCH_ASSOC);
    }

    public function setOrderPaid(int $id): void
    {
        $this->pdo->prepare("UPDATE commandes SET statut='payée' WHERE id_commande = :id")
            ->execute([':id' => $id]);
    }

    public function insertValidatedPayment(int $id, float $amount, string $provider = 'mock'): void
    {
        $this->pdo->prepare("
            INSERT INTO paiements (id_commande, montant, mode_paiement, statut, fournisseur, transaction_ref)
            VALUES (:id, :m, 'CB', 'validé', :p, CONCAT('pi_', FLOOR(RAND()*1000000000)))
        ")->execute([':id' => $id, ':m' => $amount, ':p' => $provider]);
    }
}
