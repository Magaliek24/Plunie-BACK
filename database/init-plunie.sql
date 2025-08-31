DROP DATABASE IF EXISTS plunie_db;

CREATE DATABASE IF NOT EXISTS plunie_db
  DEFAULT CHARACTER SET utf8mb4
  DEFAULT COLLATE utf8mb4_unicode_ci;
USE plunie_db;

-- ===== Utilisateurs =====
CREATE TABLE IF NOT EXISTS utilisateurs (
  id_utilisateur              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nom             VARCHAR(100) NOT NULL,
  prenom          VARCHAR(100) NOT NULL,
  email           VARCHAR(190) NOT NULL UNIQUE,
  password_hash   VARCHAR(255) NOT NULL,
  role            ENUM('client','admin') NOT NULL DEFAULT 'client',
  is_active       TINYINT(1) NOT NULL DEFAULT 1,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- ===== Adresses (liées à un user) =====
CREATE TABLE IF NOT EXISTS adresses (
  id_adresse              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  id_utilisateur         INT UNSIGNED NOT NULL,
  label           VARCHAR(100) NULL,                   -- "Domicile", "Bureau"...
  nom             VARCHAR(100) NOT NULL,
  prenom          VARCHAR(100) NOT NULL,
  num_telephone   VARCHAR(40)  NULL,
  adresse1       VARCHAR(190) NOT NULL,
  adresse2       VARCHAR(190) NULL,
  code_postal     VARCHAR(20)  NOT NULL,
  ville           VARCHAR(120) NOT NULL,
  pays            VARCHAR(2)   NOT NULL DEFAULT 'FR',  -- ISO-3166-1 alpha-2
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_adresses_utilisateurs
    FOREIGN KEY (id_utilisateur) REFERENCES utilisateurs(id_utilisateur)
    ON DELETE CASCADE ON UPDATE CASCADE
);

-- ===== Catégories de produits =====
CREATE TABLE IF NOT EXISTS categories (
  id_categorie    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nom             VARCHAR(120) NOT NULL,
  slug            VARCHAR(140) NOT NULL UNIQUE,
  description     TEXT NULL,
  image_url       VARCHAR(255) NULL,
  is_active       TINYINT(1) NOT NULL DEFAULT 1,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- ===== Produits =====
CREATE TABLE IF NOT EXISTS produits (
  id_produit      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  id_categorie    INT UNSIGNED NOT NULL,
  nom             VARCHAR(160) NOT NULL,
  slug            VARCHAR(180) NOT NULL UNIQUE,
  description_courte     TEXT NULL,
  description_longue     TEXT NULL,
  type_produit    ENUM('chaussures','semelles','entretien') NOT NULL DEFAULT 'chaussures',
  prix            DECIMAL(10,2) NOT NULL,
  prix_promo      DECIMAL(10,2) NULL,
  meta_title      VARCHAR(150) NULL,
  meta_description VARCHAR(255) NULL,
  is_active       TINYINT(1) NOT NULL DEFAULT 1,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_categorie_produit (id_categorie),
  INDEX idx_produit_actif (is_active),
  CONSTRAINT fk_categorie_produits
    FOREIGN KEY (id_categorie) REFERENCES categories(id_categorie)
    ON DELETE RESTRICT ON UPDATE CASCADE
);

-- ===== Variations (pointures/couleurs/sku/stock) =====
CREATE TABLE IF NOT EXISTS variations_produits (
  id_variation    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  id_produit      INT UNSIGNED NOT NULL,
  taille          VARCHAR(20)  NOT NULL,  -- ex: "22","23","24"
  couleur         VARCHAR(40)  NULL,      -- optionnel
  sku             VARCHAR(80)  NOT NULL UNIQUE,
  stock           INT UNSIGNED NOT NULL DEFAULT 0,    -- >= 0
  prix_variation  DECIMAL(10,2) NULL,            -- si prix spécifique; sinon NULL = prix du produit
  image_url       VARCHAR(255) NULL,
  is_active       TINYINT(1) NOT NULL DEFAULT 1,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_variation_par_produit (id_produit, taille, couleur),
  CONSTRAINT fk_variations_produits
    FOREIGN KEY (id_produit) REFERENCES produits(id_produit)
    ON DELETE CASCADE ON UPDATE CASCADE
);

-- ===== Images produit =====
CREATE TABLE IF NOT EXISTS images_produits (
  id_image_produit     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  id_produit      INT UNSIGNED NOT NULL,
  image_url       VARCHAR(255) NOT NULL,       -- chemin public/uploads/...
  alt_text        VARCHAR(190) NULL,
  is_primary      TINYINT(1) NOT NULL DEFAULT 1,
  ordre           INT UNSIGNED NOT NULL DEFAULT 1,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_images_produits
    FOREIGN KEY (id_produit) REFERENCES produits(id_produit)
    ON DELETE CASCADE ON UPDATE CASCADE,
  UNIQUE KEY uk_images_ordre (id_produit, ordre),
  INDEX idx_images_produit_ordre (id_produit, ordre),
  INDEX idx_images_produit_primaire (id_produit, is_primary)
);

-- ===== Panier (guest ou user) =====
CREATE TABLE IF NOT EXISTS paniers (
  id_panier       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  id_utilisateur  INT UNSIGNED NULL,      -- NULL si invité
  token           CHAR(36) NULL,          -- UUID pour invité
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_panier_token (token),
  INDEX idx_panier_utilisateur (id_utilisateur),
  CONSTRAINT fk_panier_utilisateur
    FOREIGN KEY (id_utilisateur) REFERENCES utilisateurs(id_utilisateur)
    ON DELETE SET NULL ON UPDATE CASCADE
);

CREATE TABLE IF NOT EXISTS panier_articles (
  id_article           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  id_panier            INT UNSIGNED NOT NULL,
  id_variation         INT UNSIGNED NOT NULL,
  quantite             INT UNSIGNED NOT NULL DEFAULT 1,
  created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_panier_prod (id_panier, id_variation),
  CONSTRAINT fk_panier_articles
    FOREIGN KEY (id_panier) REFERENCES paniers(id_panier)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_variations_articles
    FOREIGN KEY (id_variation) REFERENCES variations_produits(id_variation)
    ON DELETE RESTRICT ON UPDATE CASCADE
);

-- ===== Commandes =====
CREATE TABLE IF NOT EXISTS commandes (
  id_commande             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  id_utilisateur          INT UNSIGNED NULL,   -- autorise commande invité
  id_panier               INT UNSIGNED NULL,      -- origine panier (trace)
  statut                  ENUM('en attente','payée','annulée','expédiée','livrée','remboursée')
                          NOT NULL DEFAULT 'en attente',
  total                   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  devise                  CHAR(3) NOT NULL DEFAULT 'EUR',

  -- Snapshot adresses (pour figer au moment de la commande)
  shipping_prenom         VARCHAR(100) NOT NULL,
  shipping_nom            VARCHAR(100) NOT NULL,
  shipping_num_tel        VARCHAR(40)  NULL,
  shipping_adresse1       VARCHAR(190) NOT NULL,
  shipping_adresse2       VARCHAR(190) NULL,
  shipping_code_postal    VARCHAR(20)  NOT NULL,
  shipping_ville          VARCHAR(120) NOT NULL,
  shipping_pays           VARCHAR(2)   NOT NULL DEFAULT 'FR',

  billing_prenom          VARCHAR(100) NOT NULL,
  billing_nom             VARCHAR(100) NOT NULL,
  billing_num_tel         VARCHAR(40)  NULL,
  billing_adresse1        VARCHAR(190) NOT NULL,
  billing_adresse2        VARCHAR(190) NULL,
  billing_code_postal     VARCHAR(20)  NOT NULL,
  billing_ville           VARCHAR(120) NOT NULL,
  billing_pays            VARCHAR(2)   NOT NULL DEFAULT 'FR',

  created_at              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  INDEX idx_commande_utilisateur (id_utilisateur),
  INDEX idx_commande_statut (statut),
  CONSTRAINT fk_commandes_utilisateur
    FOREIGN KEY (id_utilisateur) REFERENCES utilisateurs(id_utilisateur)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_commandes_panier
    FOREIGN KEY (id_panier) REFERENCES paniers(id_panier)
    ON DELETE SET NULL ON UPDATE CASCADE
);

CREATE TABLE IF NOT EXISTS commande_articles (
  id_commande_article     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  id_commande          INT UNSIGNED NOT NULL,
  id_produit           INT UNSIGNED NOT NULL,
  id_variation         INT UNSIGNED NOT NULL,
  nom_produit          VARCHAR(160) NOT NULL,        -- snapshot
  variation            VARCHAR(80)  NULL,            -- ex: "24 / Bleu"
  prix_unitaire        DECIMAL(10,2) NOT NULL,       -- prix au moment T
  quantite             INT UNSIGNED NOT NULL,
  total_ligne          DECIMAL(10,2) NOT NULL,       -- prix_unitaire * quantite
  CONSTRAINT fk_articles_commande
    FOREIGN KEY (id_commande) REFERENCES commandes(id_commande)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_articles_produit
    FOREIGN KEY (id_produit) REFERENCES produits(id_produit)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_articles_variation
    FOREIGN KEY (id_variation) REFERENCES variations_produits(id_variation)
    ON DELETE RESTRICT ON UPDATE CASCADE
);

-- ===== Paiements (mock) =====
CREATE TABLE IF NOT EXISTS paiements (
  id_paiement     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  id_commande     INT UNSIGNED NOT NULL,
  montant         DECIMAL(10,2) NOT NULL,
  mode_paiement   ENUM('CB','Paypal') NOT NULL DEFAULT 'CB',
  statut          ENUM('en attente','validé','échoué','remboursé') NOT NULL DEFAULT 'en attente',
  fournisseur     VARCHAR(32) NOT NULL DEFAULT 'mock',
  transaction_ref VARCHAR(100) NULL,
  payload         TEXT NULL,           -- logs/retours du fournisseur
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_paiements_commande
    FOREIGN KEY (id_commande) REFERENCES commandes(id_commande)
    ON DELETE CASCADE ON UPDATE CASCADE
);

-- ===== Expéditions =====
CREATE TABLE IF NOT EXISTS expeditions (
  id_expedition   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  id_commande     INT UNSIGNED NOT NULL,
  type_livraison  ENUM('domicile','point_relais') NOT NULL DEFAULT 'point_relais',
  transporteur    VARCHAR(60)  NULL,           -- "LaPoste", "UPS"...
  numero_tracking VARCHAR(100) NULL,
  statut          ENUM('en attente','envoyé','livré') NOT NULL DEFAULT 'en attente',
  date_envoi      DATETIME NULL,
  date_livraison  DATETIME NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_expeditions_commande
    FOREIGN KEY (id_commande) REFERENCES commandes(id_commande)
    ON DELETE CASCADE ON UPDATE CASCADE
);

-- ===== Codes promo =====
CREATE TABLE IF NOT EXISTS codes_promo (
  id_code_promo     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code              VARCHAR(40) NOT NULL UNIQUE,
  type_code         ENUM('pourcentage','montant fixe') NOT NULL DEFAULT 'pourcentage',
  valeur            DECIMAL(10,2) NOT NULL,           -- % ou montant
  date_debut        DATETIME NULL,
  date_fin          DATETIME NULL,
  utilisations_max  INT UNSIGNED NULL,
  nb_utilisation   INT UNSIGNED NOT NULL DEFAULT 0,
  is_active         TINYINT(1) NOT NULL DEFAULT 1,
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- ===== Avis produits =====
CREATE TABLE IF NOT EXISTS avis_produits (
  id_avis          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  id_produit       INT UNSIGNED NOT NULL,
  id_utilisateur   INT UNSIGNED NULL,      -- NULL si invité
  note             TINYINT UNSIGNED NOT NULL,   -- 1..5
  titre            VARCHAR(120) NULL,
  commentaire      TEXT NULL,
  statut           ENUM('en_attente','approuve','rejete') NOT NULL DEFAULT 'en_attente',
  created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_avis_produit
    FOREIGN KEY (id_produit) REFERENCES produits(id_produit)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_avis_user
    FOREIGN KEY (id_utilisateur) REFERENCES utilisateurs(id_utilisateur)
    ON DELETE SET NULL ON UPDATE CASCADE,
  INDEX idx_avis_produit_statut (id_produit, statut)
);


