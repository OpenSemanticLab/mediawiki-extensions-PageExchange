-- Upgrade pxp_package_data from TEXT (64KB) to MEDIUMTEXT (16MB)
-- to support large packages with thousands of pages.
ALTER TABLE /*_*/px_packages MODIFY pxp_package_data MEDIUMTEXT NOT NULL;
