-- ── Category ──────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS Category (
  categoryID    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  category_name VARCHAR(80) NOT NULL
) ENGINE=InnoDB;

-- ── Tag ───────────────────────────────────────────────────────────
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
  password       VARCHAR(255) NOT NULL,
  date_joined    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  address        TEXT,
  contact_number VARCHAR(20),
  role           ENUM('user', 'admin') NOT NULL DEFAULT 'user'
) ENGINE=InnoDB;

-- ── Users_image ───────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS Users_image (
  u_imageID  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  userID     INT UNSIGNED NOT NULL,
  user_image VARCHAR(500) NOT NULL COMMENT 'file path or URL',
  FOREIGN KEY (userID) REFERENCES Users(userID) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ── Item ──────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS Item (
  itemID      INT UNSIGNED   AUTO_INCREMENT PRIMARY KEY,
  sellerID    INT UNSIGNED   NOT NULL,
  categoryID  INT UNSIGNED   NOT NULL,
  title       VARCHAR(255)   NOT NULL,
  price       DECIMAL(12, 2) NOT NULL,
  description TEXT,
  address     VARCHAR(255),
  status      ENUM('available', 'sold', 'archived') NOT NULL DEFAULT 'available',
  created_at  DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (sellerID)   REFERENCES Users(userID)        ON DELETE CASCADE,
  FOREIGN KEY (categoryID) REFERENCES Category(categoryID)
) ENGINE=InnoDB;

-- ── Item_Tag ──────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS Item_Tag (
  itemID INT UNSIGNED NOT NULL,
  tagID  INT UNSIGNED NOT NULL,
  PRIMARY KEY (itemID, tagID),
  FOREIGN KEY (itemID) REFERENCES Item(itemID) ON DELETE CASCADE,
  FOREIGN KEY (tagID)  REFERENCES Tag(tagID)   ON DELETE CASCADE
) ENGINE=InnoDB;

-- ── Image ─────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS Image (
  imageID INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  itemID  INT UNSIGNED NOT NULL,
  images  VARCHAR(500) NOT NULL COMMENT 'file path or URL',
  FOREIGN KEY (itemID) REFERENCES Item(itemID) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ── Reviews ───────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS Reviews (
  reviewID   INT UNSIGNED     AUTO_INCREMENT PRIMARY KEY,
  itemID     INT UNSIGNED     NOT NULL,
  userID     INT UNSIGNED     NOT NULL,
  rating     TINYINT UNSIGNED NOT NULL CHECK (rating BETWEEN 1 AND 5),
  body       TEXT,
  created_at DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_review (itemID, userID),
  FOREIGN KEY (itemID) REFERENCES Item(itemID)  ON DELETE CASCADE,
  FOREIGN KEY (userID) REFERENCES Users(userID) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ── Transaction ───────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS Transaction (
  transactionID    INT UNSIGNED   AUTO_INCREMENT PRIMARY KEY,
  sellerID         INT UNSIGNED   NOT NULL,
  buyerID          INT UNSIGNED   NOT NULL,
  itemID           INT UNSIGNED   NOT NULL,
  price            DECIMAL(12, 2) NOT NULL,

  -- Each party independently records their decision
  seller_agreement ENUM('pending', 'agreed', 'rejected') NOT NULL DEFAULT 'pending',
  buyer_agreement  ENUM('pending', 'agreed', 'rejected') NOT NULL DEFAULT 'pending',

  -- Overall transaction lifecycle gate
  payment_status   ENUM('pending', 'ready_for_payment', 'completed', 'cancelled')
                   NOT NULL DEFAULT 'pending',

  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                            ON UPDATE CURRENT_TIMESTAMP,

  FOREIGN KEY (sellerID) REFERENCES Users(userID) ON DELETE RESTRICT,
  FOREIGN KEY (buyerID)  REFERENCES Users(userID) ON DELETE RESTRICT,
  FOREIGN KEY (itemID)   REFERENCES Item(itemID)  ON DELETE RESTRICT
) ENGINE=InnoDB;

-- ── Payment ───────────────────────────────────────────────────────
-- CHANGED: added status and paid_at for a complete audit trail.
CREATE TABLE IF NOT EXISTS Payment (
  paymentID      INT UNSIGNED   AUTO_INCREMENT PRIMARY KEY,
  transactionID  INT UNSIGNED   NOT NULL,
  payment_method VARCHAR(80)    NOT NULL,
  amount         DECIMAL(12, 2) NOT NULL,
  status         ENUM('pending', 'completed', 'failed') NOT NULL DEFAULT 'pending',
  paid_at        DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
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
CREATE TABLE IF NOT EXISTS Conversation (
  conversationID CHAR(36)     PRIMARY KEY COMMENT 'UUID',
  userID_1       INT UNSIGNED NOT NULL,
  userID_2       INT UNSIGNED NOT NULL,
  -- Always store the lower ID in userID_1 to prevent duplicate pairs
  CONSTRAINT chk_user_order CHECK (userID_1 < userID_2),
  UNIQUE KEY uq_conversation (userID_1, userID_2),
  FOREIGN KEY (userID_1) REFERENCES Users(userID) ON DELETE CASCADE,
  FOREIGN KEY (userID_2) REFERENCES Users(userID) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ── Messages ──────────────────────────────────────────────────────
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

-- ── Views ─────────────────────────────────────────────────────────

CREATE OR REPLACE VIEW vw_items AS
SELECT
    i.itemID,
    i.title,
    i.price,
    i.description,
    i.address,
    i.status,
    i.created_at,

    -- Seller info
    i.sellerID,
    u.username      AS seller_username,
    u.email         AS seller_email,

    -- Category
    c.categoryID,
    c.category_name,

    -- Tags aggregated into a comma-separated string
    GROUP_CONCAT(
        t.name
        ORDER BY t.name ASC
        SEPARATOR ', '
    )               AS tags,

    -- Tag IDs if you need them for filtering/joining
    GROUP_CONCAT(
        t.tagID
        ORDER BY t.name ASC
        SEPARATOR ','
    )               AS tag_ids

FROM Item i
JOIN Users    u  ON u.userID    = i.sellerID
JOIN Category c  ON c.categoryID = i.categoryID
LEFT JOIN Item_Tag it ON it.itemID = i.itemID
LEFT JOIN Tag      t  ON t.tagID   = it.tagID
GROUP BY
    i.itemID,
    i.title,
    i.price,
    i.description,
    i.address,
    i.status,
    i.created_at,
    i.sellerID,
    u.username,
    u.email,
    c.categoryID,
    c.category_name;