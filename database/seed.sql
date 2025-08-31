USE plunie_db;

-- 1) Catégories (IDs attendus: 1..4 si base neuve)
INSERT INTO categories (nom, slug, description, is_active) VALUES
('Baskets', 'baskets', 'Baskets barefoot', 1),
('Boots / Bottines', 'boots-bottines', 'Boots en cuir', 1),
('Semelles intérieures', 'semelles-interieures', 'Semelles amovibles', 1),
("Produits d'entretien", 'produit-entretien', "Entretien cuir/textile", 1);

-- 2) Produits (référence par ID de catégorie ↑)
INSERT INTO produits (id_categorie, nom, slug, description_courte, description_longue, type_produit, prix, is_active)
VALUES
(1, 'Baskets en toile Solin', 'baskets-solin', 'Baskets souples enfant', 'Description courte.', 'chaussures', 56.00, 1),
(2, 'Boots Sirius',            'boots-sirius',  'Boots souples double scratch', 'Description courte.', 'chaussures', 69.00, 1),
(2, 'Chelsea Arae',            'chelsea-arae',  'Chelsea boots souples', 'Description courte.', 'chaussures', 69.00, 1),
(3, 'Semelles réversibles',    'semelles-reversibles', 'Cuir/feutre', 'Description courte.', 'semelles', 5.00, 1);

-- 3) Récup IDs produits (simple et lisible)
SET @p_solin  := (SELECT id_produit FROM produits WHERE slug='baskets-solin');
SET @p_sirius := (SELECT id_produit FROM produits WHERE slug='boots-sirius');
SET @p_arae   := (SELECT id_produit FROM produits WHERE slug='chelsea-arae');
SET @p_sem    := (SELECT id_produit FROM produits WHERE slug='semelles-reversibles');

-- 4) Variations (peu de lignes, NULL est une vraie valeur)
INSERT INTO variations_produits (id_produit, taille, couleur, sku, stock, prix_variation, is_active) VALUES
(@p_solin,  '20', 'Marine',    'SOLIN-20-MARINE', 12, NULL, 1),
(@p_solin,  '21', 'Grenadine', 'SOLIN-21-GRENADINE', 10, NULL, 1),

(@p_sirius, '20', 'Marine', 'SIRIUS-20-MARINE', 14, NULL, 1),
(@p_sirius, '21', 'Cognac', 'SIRIUS-21-COGNAC',  8, NULL, 1),

(@p_arae,   '23', 'Cognac', 'ARAE-23-COGNAC', 25, NULL, 1),
(@p_arae,   '24', 'Marine', 'ARAE-24-MARINE', 20, NULL, 1),

(@p_sem,    '24', NULL, 'SEMELLES-24', 20, NULL, 1);

-- 5) Images (1 principale par produit)
INSERT INTO images_produits (id_produit, image_url, alt_text, is_primary, ordre) VALUES
(@p_solin,  '/uploads/solin-1.jpg',   'Solin',  1, 1),
(@p_sirius, '/uploads/sirius-1.jpg',  'Sirius', 1, 1),
(@p_arae,   '/uploads/arae-1.jpg',    'Arae',   1, 1),
(@p_sem,    '/uploads/semelles-1.jpg','Semelles',1,1);

-- 6) Utilisateurs (admin + 1 cliente)
INSERT INTO utilisateurs (nom, prenom, email, password_hash, role) VALUES
('Admin',  'Plunie', 'admin@plunie.fr',  '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin'),
('Dubois', 'Marie',  'marie@exemple.fr', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'client');

SET @u_marie := (SELECT id_utilisateur FROM utilisateurs WHERE email='marie@exemple.fr');

-- 7) Panier + 1 article
INSERT INTO paniers (id_utilisateur, token) VALUES (@u_marie, NULL);
SET @panier_marie := LAST_INSERT_ID();

SET @v_solin := (SELECT id_variation FROM variations_produits WHERE sku='SOLIN-20-MARINE');
INSERT INTO panier_articles (id_panier, id_variation, quantite) VALUES (@panier_marie, @v_solin, 1);

-- 8) Commande minimale (sans id_panier, pour simplifier)
INSERT INTO commandes (
  id_utilisateur, id_panier, statut, total, devise,
  shipping_prenom, shipping_nom, shipping_adresse1, shipping_code_postal, shipping_ville, shipping_pays,
  billing_prenom,  billing_nom,  billing_adresse1,  billing_code_postal,  billing_ville,  billing_pays
) VALUES (
  @u_marie, NULL, 'livrée', 56.00, 'EUR',
  'Marie','Dubois','15 rue des Lilas','75001','Paris','FR',
  'Marie','Dubois','15 rue des Lilas','75001','Paris','FR'
);
SET @cmd := LAST_INSERT_ID();

INSERT INTO commande_articles (id_commande, id_produit, id_variation, nom_produit, variation, prix_unitaire, quantite, total_ligne)
SELECT @cmd, p.id_produit, v.id_variation, p.nom, CONCAT(v.taille,' / ',COALESCE(v.couleur,'-')),
       COALESCE(v.prix_variation, p.prix), 1, COALESCE(v.prix_variation, p.prix)
FROM produits p
JOIN variations_produits v ON v.id_produit=p.id_produit
WHERE v.sku='SOLIN-20-MARINE'
LIMIT 1;

INSERT INTO paiements (id_commande, montant, mode_paiement, statut, fournisseur, transaction_ref)
VALUES (@cmd, 56.00, 'CB', 'validé', 'cb', 'pi_demo');

INSERT INTO expeditions (id_commande, type_livraison, transporteur, numero_tracking, statut, date_envoi, date_livraison)
VALUES (@cmd, 'domicile', 'La Poste', 'TRACK123', 'livré', NOW(), NOW());

-- 9) Avis (1 approuvé)
INSERT INTO avis_produits (id_produit, id_utilisateur, note, titre, commentaire, statut)
VALUES (@p_solin, @u_marie, 5, 'Top', 'Très souples, parfaites.', 'approuve');
