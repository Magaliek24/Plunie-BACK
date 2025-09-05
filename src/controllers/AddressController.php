<?php

declare(strict_types=1);

namespace App\controllers;

use App\core\Database;
use PDO;

final class AddressController
{
    public function list(): array
    {
        $uid = $this->requireAuth();
        $pdo = Database::pdo();
        $st = $pdo->prepare("
            SELECT id_adresse AS id, label, nom, prenom, num_telephone, 
                   adresse1, adresse2, code_postal, ville, pays, created_at, updated_at
            FROM adresses
            WHERE id_utilisateur = :u
            ORDER BY updated_at DESC, id_adresse DESC
        ");
        $st->execute([':u' => $uid]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        return $this->resp(200, ['ok' => true, 'data' => $rows]);
    }

    public function create(): array
    {
        $uid  = $this->requireAuth();
        $data = $this->readJson();
        $this->requireCsrf($data);

        [$clean, $errors] = $this->validate($data);
        if ($errors) {
            return $this->resp(422, ['ok' => false, 'errors' => $errors]);
        }

        $pdo = Database::pdo();
        $st = $pdo->prepare("
            INSERT INTO adresses (id_utilisateur,label,nom,prenom,num_telephone,adresse1,adresse2,code_postal,ville,pays)
            VALUES (:u,:label,:nom,:prenom,:tel,:a1,:a2,:cp,:ville,:pays)
        ");
        $st->execute([
            ':u'     => $uid,
            ':label' => $clean['label'],
            ':nom'   => $clean['nom'],
            ':prenom' => $clean['prenom'],
            ':tel'   => $clean['num_telephone'],
            ':a1'    => $clean['adresse1'],
            ':a2'    => $clean['adresse2'],
            ':cp'    => $clean['code_postal'],
            ':ville' => $clean['ville'],
            ':pays'  => $clean['pays'],
        ]);

        $id = (int)$pdo->lastInsertId();
        return $this->resp(201, ['ok' => true, 'data' => ['id' => $id] + $clean]);
    }

    public function update(int $id): array
    {
        $uid  = $this->requireAuth();
        $data = $this->readJson();
        $this->requireCsrf($data);

        // appartient bien à l'utilisateur ?
        if (!$this->owns($id, $uid)) {
            return $this->resp(404, ['ok' => false, 'error' => 'not_found']);
        }

        [$clean, $errors] = $this->validate($data);
        if ($errors) {
            return $this->resp(422, ['ok' => false, 'errors' => $errors]);
        }

        $pdo = Database::pdo();
        $st = $pdo->prepare("
            UPDATE adresses
            SET label=:label, nom=:nom, prenom=:prenom, num_telephone=:tel,
                adresse1=:a1, adresse2=:a2, code_postal=:cp, ville=:ville, pays=:pays
            WHERE id_adresse=:id AND id_utilisateur=:u
        ");
        $st->execute([
            ':label' => $clean['label'],
            ':nom'   => $clean['nom'],
            ':prenom' => $clean['prenom'],
            ':tel'   => $clean['num_telephone'],
            ':a1'    => $clean['adresse1'],
            ':a2'    => $clean['adresse2'],
            ':cp'    => $clean['code_postal'],
            ':ville' => $clean['ville'],
            ':pays'  => $clean['pays'],
            ':id'    => $id,
            ':u'     => $uid,
        ]);

        return $this->resp(200, ['ok' => true]);
    }

    public function delete(int $id): array
    {
        $uid  = $this->requireAuth();
        $data = $this->readJson(); // permet d’accepter csrf en JSON
        $this->requireCsrf($data);

        if (!$this->owns($id, $uid)) {
            return $this->resp(404, ['ok' => false, 'error' => 'not_found']);
        }

        $pdo = Database::pdo();
        $st = $pdo->prepare("DELETE FROM adresses WHERE id_adresse=:id AND id_utilisateur=:u");
        $st->execute([':id' => $id, ':u' => $uid]);

        return $this->resp(200, ['ok' => true]);
    }

    /* ---------------- helpers ---------------- */

    private function readJson(): array
    {
        $raw = file_get_contents('php://input') ?: '';
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    private function requireAuth(): int
    {
        $uid = (int)($_SESSION['user_id'] ?? 0);
        if ($uid <= 0) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(401);
            echo json_encode(['ok' => false, 'error' => 'unauthenticated']);
            exit;
        }
        return $uid;
    }

    private function requireCsrf(array $data): void
    {
        $hdr = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        $tok = (string)($data['csrf_token'] ?? $hdr);
        if (!function_exists('verify_csrf_token') || !verify_csrf_token($tok)) {
            json_response(['ok' => false, 'error' => 'csrf_invalid'], 400);
        }
    }

    private function owns(int $addressId, int $userId): bool
    {
        $pdo = Database::pdo();
        $st = $pdo->prepare("SELECT 1 FROM adresses WHERE id_adresse=:id AND id_utilisateur=:u LIMIT 1");
        $st->execute([':id' => $addressId, ':u' => $userId]);
        return (bool)$st->fetchColumn();
    }

    private function validate(array $data): array
    {
        $label  = trim((string)($data['label'] ?? ''));
        $nom    = trim((string)($data['nom'] ?? ''));
        $prenom = trim((string)($data['prenom'] ?? ''));
        $telRaw = trim((string)($data['num_telephone'] ?? ''));
        $a1     = trim((string)($data['adresse1'] ?? ''));
        $a2     = trim((string)($data['adresse2'] ?? ''));
        $cp     = trim((string)($data['code_postal'] ?? ''));
        $ville  = trim((string)($data['ville'] ?? ''));
        $pays   = strtoupper(trim((string)($data['pays'] ?? 'FR')));

        $errors = [];
        if ($nom === '')    $errors['nom'] = 'Requis';
        if ($prenom === '') $errors['prenom'] = 'Requis';
        if ($a1 === '')     $errors['adresse1'] = 'Requis';
        if ($cp === '')     $errors['code_postal'] = 'Requis';
        if ($ville === '')  $errors['ville'] = 'Requis';
        if (strlen($pays) !== 2) $errors['pays'] = 'Code ISO2 attendu';

        if ($telRaw !== '' && !preg_match('/^[0-9+\s().-]{6,20}$/', $telRaw)) {
            $errors['num_telephone'] = 'Format invalide';
        }

        $clean = [
            'label'         => $label !== '' ? $label : null,
            'nom'           => $nom,
            'prenom'        => $prenom,
            'num_telephone' => $telRaw !== '' ? $telRaw : null,
            'adresse1'      => $a1,
            'adresse2'      => $a2 !== '' ? $a2 : null,
            'code_postal'   => $cp,
            'ville'         => $ville,
            'pays'          => $pays,
        ];
        return [$clean, $errors];
    }

    private function resp(int $status, array $body): array
    {
        return ['status' => $status, 'body' => $body];
    }
}
