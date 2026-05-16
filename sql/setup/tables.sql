-- CREATE DATABASE IF NOT EXISTS demys_db
--   CHARACTER SET utf8mb4
--   COLLATE utf8mb4_unicode_ci;

-- USE demys_db;

-- ── Category ──────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS Category (
  categoryID    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  category_name VARCHAR(80) NOT NULL
) ENGINE=InnoDB;

-- INSERT IGNORE INTO Category (categoryID, category_name) VALUES
--   (1, 'Vehicles'),
--   (2, 'Devices'),
--   (3, 'Clothes'),
--   (4, 'Sports'),
--   (5, 'Furniture'),
--   (6, 'Books'),
--   (7, 'Others');

-- ── Tag ───────────────────────────────────────────────────────────
-- Free-form tags for image-board style filtering (e.g. "vintage", "negotiable")
CREATE TABLE IF NOT EXISTS Tag (
  tagID INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name  VARCHAR(50) NOT NULL,
  UNIQUE KEY uq_tag_name (name)
) ENGINE=InnoDB;

-- ── Users ─────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS Users (
  userID         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email          VARCHAR(255) NOT NULL UNIQUE,
  username       VARCHAR(80)  NOT NULL UNIQUE,
  password       VARCHAR(255) NOT NULL COMMENT 'bcrypt hash',
  date_joined    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  address        TEXT,
  contact_number VARCHAR(20),
  role           ENUM('buyer', 'seller', 'admin') NOT NULL DEFAULT 'buyer'
) ENGINE=InnoDB;

-- ── Item ──────────────────────────────────────────────────────────
-- Note: ratingID removed — compute avg rating via query on Reviews
CREATE TABLE IF NOT EXISTS Item (
  itemID      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sellerID    INT UNSIGNED    NOT NULL,
  categoryID  INT UNSIGNED    NOT NULL,
  title       VARCHAR(255)    NOT NULL,
  price       DECIMAL(12, 2)  NOT NULL,
  description TEXT,
  address     VARCHAR(255),
  status      ENUM('active', 'sold', 'archived') NOT NULL DEFAULT 'active',
  created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (sellerID)   REFERENCES Users(userID)        ON DELETE CASCADE,
  FOREIGN KEY (categoryID) REFERENCES Category(categoryID)
) ENGINE=InnoDB;

-- ── Item_Tag ──────────────────────────────────────────────────────
-- Many-to-many: one item can have multiple tags, one tag on many items
CREATE TABLE IF NOT EXISTS Item_Tag (
  itemID INT UNSIGNED NOT NULL,
  tagID  INT UNSIGNED NOT NULL,
  PRIMARY KEY (itemID, tagID),
  FOREIGN KEY (itemID) REFERENCES Item(itemID) ON DELETE CASCADE,
  FOREIGN KEY (tagID)  REFERENCES Tag(tagID)   ON DELETE CASCADE
) ENGINE=InnoDB;

-- ── Image ─────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS Image (
  imageID  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  itemID   INT UNSIGNED NOT NULL,
  images   VARCHAR(500) NOT NULL COMMENT 'file path or URL',
  FOREIGN KEY (itemID) REFERENCES Item(itemID) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ── Reviews ───────────────────────────────────────────────────────
-- Merged with Rating: one row owns both the star value and the review text.
-- Use AVG(rating) WHERE itemID = ? to compute the item's average rating.
CREATE TABLE IF NOT EXISTS Reviews (
  reviewID   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  itemID     INT UNSIGNED    NOT NULL,
  userID     INT UNSIGNED    NOT NULL,
  rating     TINYINT UNSIGNED NOT NULL CHECK (rating BETWEEN 1 AND 5),
  body       TEXT,
  created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_review (itemID, userID),   -- one review per user per item
  FOREIGN KEY (itemID) REFERENCES Item(itemID)  ON DELETE CASCADE,
  FOREIGN KEY (userID) REFERENCES Users(userID) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ── Transaction ───────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS Transaction (
  transactionID INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sellerID      INT UNSIGNED   NOT NULL,
  buyerID       INT UNSIGNED   NOT NULL,
  itemID        INT UNSIGNED   NOT NULL,
  price         DECIMAL(12, 2) NOT NULL,
  created_at    DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (sellerID) REFERENCES Users(userID) ON DELETE RESTRICT,
  FOREIGN KEY (buyerID)  REFERENCES Users(userID) ON DELETE RESTRICT,
  FOREIGN KEY (itemID)   REFERENCES Item(itemID)  ON DELETE RESTRICT
) ENGINE=InnoDB;

-- ── Payment ───────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS Payment (
  paymentID      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  transactionID  INT UNSIGNED   NOT NULL,
  payment_method VARCHAR(80)    NOT NULL,
  amount         DECIMAL(12, 2) NOT NULL,
  FOREIGN KEY (transactionID) REFERENCES Transaction(transactionID) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ── Wishlist ──────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS Wishlist (
  wishID INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  userID INT UNSIGNED NOT NULL,
  itemID INT UNSIGNED NOT NULL,
  UNIQUE KEY uq_wish (userID, itemID),
  FOREIGN KEY (userID) REFERENCES Users(userID) ON DELETE CASCADE,
  FOREIGN KEY (itemID) REFERENCES Item(itemID)  ON DELETE CASCADE
) ENGINE=InnoDB;

-- ── Conversation ──────────────────────────────────────────────────
-- UNIQUE ensures only one conversation thread exists per pair of users
CREATE TABLE IF NOT EXISTS Conversation (
  conversationID CHAR(36)     PRIMARY KEY COMMENT 'UUID',
  userID_1       INT UNSIGNED NOT NULL,
  userID_2       INT UNSIGNED NOT NULL,
  UNIQUE KEY uq_conversation (userID_1, userID_2),
  FOREIGN KEY (userID_1) REFERENCES Users(userID) ON DELETE CASCADE,
  FOREIGN KEY (userID_2) REFERENCES Users(userID) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ── Messages ──────────────────────────────────────────────────────
-- sent_at replaces the previous separate date + time columns
CREATE TABLE IF NOT EXISTS Messages (
  messageID      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  conversationID CHAR(36)     NOT NULL,
  senderID       INT UNSIGNED NOT NULL,
  receiverID     INT UNSIGNED NOT NULL,
  body           TEXT         NOT NULL,
  sent_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (conversationID) REFERENCES Conversation(conversationID) ON DELETE CASCADE,
  FOREIGN KEY (senderID)       REFERENCES Users(userID) ON DELETE CASCADE,
  FOREIGN KEY (receiverID)     REFERENCES Users(userID) ON DELETE CASCADE
) ENGINE=InnoDB;