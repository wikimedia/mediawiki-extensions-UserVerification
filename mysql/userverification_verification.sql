CREATE TABLE IF NOT EXISTS /*_*/userverification_verification (
  `user_id` int(10) NOT NULL,
  `status` ENUM( "none", "pending", "verified", "not_required" ) DEFAULT NULL,
  `comments` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
  `method` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
  `data` BLOB NULL,
  `updated_at` datetime NOT NULL,
  `created_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;


ALTER TABLE /*_*/userverification_verification
  ADD PRIMARY KEY (`user_id`);


