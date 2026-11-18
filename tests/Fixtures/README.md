# Lookup fixture provenance

The fixture UUIDs are arbitrary stable test identities. Names and minimal postal associations are small factual examples checked on 18 November 2026:

- Thai Buri / ไทยบุรี / GeoNames ID 12647746, source `TH.zip` SHA-256 `f60a1eb60343ee0cf03c87c8bffdccd9240e657bd7acda6f5704ae45abd6166e`. GeoNames data uses CC BY 4.0; attribution: [GeoNames](https://www.geonames.org/).
- Thai Buri → Tha Sala → Nakhon Si Thammarat → Thailand, code 80160: [government postcode table](https://zipcode.industry.go.th/), tambon code 80080900. This is a factual example, not a complete Thai postcode dataset.
- Bethel Acres → Pottawatomie → Oklahoma → United States: GeoNames ID 4530396, `US.zip` SHA-256 `3434a3a5c5c4d19193f5a975d8ab5a61f56d2a7392a72a2cdf751c9e0d973ca9`; town context corroborated by [the town website](https://www.townofbethelacresok.net/about-our-town).
- The town's [community-centre mailing address](https://www.townofbethelacresok.net/) uses Shawnee OK 74801. This verifies a postal association, not complete town ZIP coverage. GeoNames postal `US.zip` associates 74801 with Shawnee.

**All polygons, ZZ records, the synthetic village, and numeric prefix examples are synthetic test constructs.** They do not represent official borders, real village identities, or valid postal assignments. Thai Buri's synthetic rectangle is used only to test matching and requested-depth behavior. No acquired raw national datasets are bundled. Production dataset licensing, attribution and completeness remain the producer's responsibility; PHP Apache-2.0 does not license third-party data.
