CREATE TABLE llx_novalnet_payment_transaction(
rowid integer NOT NULL AUTO_INCREMENT PRIMARY KEY,
order_id varchar(50) UNIQUE,
ref_no varchar(50),
ref_id varchar(50),
source varchar(50) ,
tid varchar(50) ,
amount float(50) ,
payment_status varchar(50) ,
customer_id varchar(50),
payment_method varchar(50),
tnx_secret varchar(50)) ENGINE=InnoDB;

CREATE TABLE llx_novalnet_callback(
rowid integer NOT NULL AUTO_INCREMENT PRIMARY KEY,
order_id varchar(50) UNIQUE,
callback_amount  float(50) ,
reference_tid varchar(50) ,
callback_tid varchar(50) ,
callback_datetime timestamp
) ENGINE=InnoDB;

CREATE TABLE llx_novalnet_credit_note(
rowid integer NOT NULL AUTO_INCREMENT PRIMARY KEY,
order_id varchar(50) UNIQUE,
ref_id varchar(50),
credit_note_id varchar(50),
refunded_amount  float(50) ,
reference_tid varchar(50) ,
callback_tid varchar(50) ,
callback_datetime timestamp
) ENGINE=InnoDB;

INSERT INTO llx_c_paiement (code,libelle,type,active) values ('NOV', 'NovalnetPay', 1, 1);

