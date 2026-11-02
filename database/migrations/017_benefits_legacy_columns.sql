-- Widen legacy fields without narrowing existing TEXT or longer filenames.

SET @panel_benefit_description_type = (
    SELECT `DATA_TYPE` FROM information_schema.columns
    WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'panel_benefits' AND `COLUMN_NAME` = 'description'
);
SET @panel_benefit_description_ddl = IF(
    @panel_benefit_description_type IS NULL,
    'ALTER TABLE `panel_benefits` ADD COLUMN `description` TEXT NULL',
    IF(@panel_benefit_description_type IN ('text', 'mediumtext', 'longtext'),
        'DO 0',
        'ALTER TABLE `panel_benefits` MODIFY `description` TEXT NULL')
);
PREPARE panel_benefit_statement FROM @panel_benefit_description_ddl;
EXECUTE panel_benefit_statement;
DEALLOCATE PREPARE panel_benefit_statement;

SET @panel_benefit_label_type = (
    SELECT `COLUMN_TYPE` FROM information_schema.columns
    WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'panel_benefits' AND `COLUMN_NAME` = 'label'
);
SET @panel_benefit_label_ddl = IF(
    @panel_benefit_label_type IS NULL,
    'ALTER TABLE `panel_benefits` ADD COLUMN `label` VARCHAR(128) NULL',
    CONCAT('ALTER TABLE `panel_benefits` MODIFY `label` ', @panel_benefit_label_type, ' NULL')
);
PREPARE panel_benefit_statement FROM @panel_benefit_label_ddl;
EXECUTE panel_benefit_statement;
DEALLOCATE PREPARE panel_benefit_statement;

SET @panel_benefit_icon_length = (
    SELECT `CHARACTER_MAXIMUM_LENGTH` FROM information_schema.columns
    WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'panel_benefits' AND `COLUMN_NAME` = 'icon'
);
SET @panel_benefit_icon_nullable = (
    SELECT `IS_NULLABLE` FROM information_schema.columns
    WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'panel_benefits' AND `COLUMN_NAME` = 'icon'
);
SET @panel_benefit_icon_ddl = IF(
    @panel_benefit_icon_length IS NULL,
    'ALTER TABLE `panel_benefits` ADD COLUMN `icon` VARCHAR(191) NOT NULL DEFAULT ''''',
    IF(@panel_benefit_icon_length < 191,
        CONCAT('ALTER TABLE `panel_benefits` MODIFY `icon` VARCHAR(191) ',
            IF(@panel_benefit_icon_nullable = 'YES', 'NULL', 'NOT NULL DEFAULT ''''')),
        'DO 0')
);
PREPARE panel_benefit_statement FROM @panel_benefit_icon_ddl;
EXECUTE panel_benefit_statement;
DEALLOCATE PREPARE panel_benefit_statement;
