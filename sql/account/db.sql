CREATE DATABASE account CHARACTER SET utf8mb4;
grant all privileges on account.* to rental@"localhost" IDENTIFIED BY 'xxxxxxxx';
grant all privileges on account.* to rental@"127.0.0.1" IDENTIFIED BY 'xxxxxxxx';
grant all privileges on account.* to rental@"192.168.0.0/255.255.0.0" IDENTIFIED BY 'xxxxxxxx';
