<?php

declare(strict_types=1);

namespace App\controllers;


use App\core\Database;
use App\repository\OrderRepository;
use App\services\CartService;

final class OrderController
{
    // POST /api/orders/checkout
    public function checkout(): array
    {
        if (!isset($_SESSION['user_id'])) {
            return ['status' => 401, 'body' => ['ok' => false, 'error' => 'unauthenticated']];
        }
        if (!function_exists('verify_csrf_token') || !verify_csrf_token(null)) {
            return ['status' => 400, 'body' => ['ok' => false, 'error' => 'csrf_invalid']];
        }

        $uid = (int)$_SESSION['user_id'];

        // Lecture payload (JSON déjà injecté dans $_POST par index.php)
        $shipping = (array)($_POST['shipping'] ?? []);
        $billing  = (array)($_POST['billing']  ?? $shipping); // fallback même adresse
        $codePromo = isset($_POST['code_promo']) ? trim((string)$_POST['code_promo']) : null;

        // Champs requis minimaux 
        foreach (['prenom', 'nom', 'adresse1', 'code_postal', 'ville'] as $f) {
            if (empty($shipping[$f]) || empty($billing[$f])) {
                return ['status' => 422, 'body' => ['ok' => false, 'error' => 'invalid_address', 'missing' => $f]];
            }
        }
        $shipping += ['pays' => 'FR', 'num_tel' => null, 'adresse2' => null];
        $billing  += ['pays' => 'FR', 'num_tel' => null, 'adresse2' => null];

        $pdo = Database::pdo();
        $order = new OrderRepository($pdo);
        $cart  = new CartService($pdo);

        $pdo->beginTransaction();
        try {
            // 1) Trouver (ou créer) le panier de l’utilisateur
            $idPanier = $cart->getUserCartId($uid);
            if ($idPanier === 0) {
                $pdo->rollBack();
                return ['status' => 400, 'body' => ['ok' => false, 'error' => 'cart_empty']];
            }

            // 2) Charger les lignes du panier
            $lines = $cart->getCartLines($idPanier); // id_variation, quantite, stock, prix_unitaire, id_produit, nom
            if (!$lines) {
                $pdo->rollBack();
                return ['status' => 400, 'body' => ['ok' => false, 'error' => 'cart_empty']];
            }

            // 3) Vérifier stock & calculer total
            $total = 0.0;
            foreach ($lines as $li) {
                $q = (int)$li['quantite'];
                if ($q < 1 || $q > (int)$li['stock']) {
                    $pdo->rollBack();
                    return ['status' => 409, 'body' => ['ok' => false, 'error' => 'out_of_stock', 'variation_id' => (int)$li['id_variation']]];
                }
                $total += ((float)$li['prix_unitaire']) * $q;
            }

            // 4) (Optionnel) appliquer un code promo très simple
            if ($codePromo) {
                $promo = $order->getPromo($codePromo);
                if ($promo && (int)$promo['is_active'] === 1) {
                    if ($promo['type_code'] === 'pourcentage') {
                        $total = round($total * (1.0 - ((float)$promo['valeur'] / 100.0)), 2);
                    } else {
                        $total = max(0.0, round($total - (float)$promo['valeur'], 2));
                    }
                }
            }

            // 5) Créer la commande
            $orderId = $order->createOrder($uid, $idPanier, $shipping, $billing, $total);

            // 6) Insérer les lignes + décrémenter le stock
            foreach ($lines as $li) {
                $q  = (int)$li['quantite'];
                $pu = (float)$li['prix_unitaire'];
                $tl = round($pu * $q, 2);

                $order->insertOrderLine(
                    $orderId,
                    (int)$li['id_produit'],
                    (int)$li['id_variation'],
                    (string)$li['nom'],
                    null, // libellé variation si besoin
                    $pu,
                    $q,
                    $tl
                );
                $order->decrementStock((int)$li['id_variation'], $q);
            }

            // 7) créer un paiement "en attente"
            $order->insertPendingPayment($orderId, $total);

            // 8) Vider le panier
            $cart->clearCart($idPanier);

            $pdo->commit();
            return ['status' => 201, 'body' => ['ok' => true, 'order_id' => $orderId, 'total' => $total]];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('checkout error: ' . $e->getMessage());
            return ['status' => 500, 'body' => ['ok' => false, 'error' => 'server_error']];
        }
    }
    // GET /api/orders (mes commandes)
    public function listMine(): array
    {
        if (!isset($_SESSION['user_id'])) {
            return ['status' => 401, 'body' => ['ok' => false, 'error' => 'unauthenticated']];
        }
        $uid = (int)$_SESSION['user_id'];
        $order = new OrderRepository(Database::pdo());
        $items = $order->listForUser($uid);
        return ['status' => 200, 'body' => ['ok' => true, 'items' => $items]];
    }

    // GET /api/orders/{id}
    public function show(int $id): array
    {
        if (!isset($_SESSION['user_id'])) {
            return ['status' => 401, 'body' => ['ok' => false, 'error' => 'unauthenticated']];
        }
        $uid = (int)$_SESSION['user_id'];
        $role = $_SESSION['user_role'] ?? 'client';

        $orderRepo = new OrderRepository(Database::pdo());
        $cmd = $orderRepo->getOrder($id);
        if (!$cmd) return ['status' => 404, 'body' => ['ok' => false, 'error' => 'not_found']];
        if ($role !== 'admin' && (int)$cmd['id_utilisateur'] !== $uid) {
            return ['status' => 403, 'body' => ['ok' => false, 'error' => 'forbidden']];
        }

        $lines    = $orderRepo->getOrderLines($id);
        $payments = $orderRepo->getPayments($id);
        $isPaid   = false;
        foreach ($payments as $p) {
            if ($p['statut'] === 'validé') {
                $isPaid = true;
                break;
            }
        }

        return ['status' => 200, 'body' => [
            'ok'       => true,
            'order'    => $cmd + ['paid' => $isPaid],
            'items'    => $lines,
            'payments' => $payments
        ]];
    }

    // POST /api/orders/{id}/pay (mock)
    public function payMock(int $id): array
    {
        if (!isset($_SESSION['user_id'])) {
            return ['status' => 401, 'body' => ['ok' => false, 'error' => 'unauthenticated']];
        }
        if (!function_exists('verify_csrf_token') || !verify_csrf_token(null)) {
            return ['status' => 400, 'body' => ['ok' => false, 'error' => 'csrf_invalid']];
        }

        $uid = (int)$_SESSION['user_id'];
        $pdo = Database::pdo();
        $ord = new OrderRepository($pdo);
        $pdo->beginTransaction();
        try {
            $cmd = $ord->getOrderForUpdate($id);
            if (!$cmd) {
                $pdo->rollBack();
                return ['status' => 404, 'body' => ['ok' => false, 'error' => 'not_found']];
            }
            if ((int)$cmd['id_utilisateur'] !== $uid) {
                $pdo->rollBack();
                return ['status' => 403, 'body' => ['ok' => false, 'error' => 'forbidden']];
            }

            $ord->setOrderPaid($id);
            $ord->insertValidatedPayment($id, (float)$cmd['total'], 'mock');

            $pdo->commit();
            return ['status' => 200, 'body' => ['ok' => true, 'paid' => true]];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('payMock error: ' . $e->getMessage());
            return ['status' => 500, 'body' => ['ok' => false, 'error' => 'server_error']];
        }
    }
}
