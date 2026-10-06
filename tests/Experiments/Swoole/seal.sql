DROP USER 'seeder'@'%';
SET GLOBAL read_only = ON;
SET GLOBAL super_read_only = ON;
SELECT @@global.read_only AS read_only, @@global.super_read_only AS super_read_only;
SHOW GRANTS FOR 'reader'@'%';
