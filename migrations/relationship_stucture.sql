ALTER TABLE domains
ADD CONSTRAINT fk_domains_province_id FOREIGN KEY (province_id) REFERENCES province(id);

ALTER TABLE domains
ADD CONSTRAINT fk_domains_city_id FOREIGN KEY (city_id) REFERENCES city(id);

ALTER TABLE domains
ADD CONSTRAINT fk_domains_district_id FOREIGN KEY (district_id) REFERENCES district(id);

ALTER TABLE domains
ADD CONSTRAINT fk_domains_village_id FOREIGN KEY (village_id) REFERENCES village(id);

ALTER TABLE domains
ADD CONSTRAINT fk_domains_klasifikasi_instansi_id FOREIGN KEY (klasifikasi_instansi_id) REFERENCES klasifikasi_instansi(id);

ALTER TABLE city
ADD CONSTRAINT fk_city_province_id FOREIGN KEY (province_id) REFERENCES province(id);

ALTER TABLE district
ADD CONSTRAINT fk_district_city_id FOREIGN KEY (city_id) REFERENCES city(id);

ALTER TABLE village
ADD CONSTRAINT fk_village_district_id FOREIGN KEY (district_id) REFERENCES district(id);

