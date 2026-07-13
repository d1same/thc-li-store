<?php
declare(strict_types=1);

namespace App;

final class Seed
{
    public static function run(): void
    {
        $pdo = Database::pdo();
        $settings = [
            'store_name' => ['THC LI', 'string'],
            'store_tagline' => ['Premium local menu. Discreet pickup and delivery.', 'string'],
            'store_phone' => ['(631) 896-1424', 'string'],
            'store_email' => ['', 'string'],
            'store_status' => ['open', 'string'],
            'ordering_enabled' => ['1', 'bool'],
            'pickup_enabled' => ['1', 'bool'],
            'delivery_enabled' => ['1', 'bool'],
            'guest_checkout_enabled' => ['1', 'bool'],
            'registration_enabled' => ['1', 'bool'],
            'manual_confirmation' => ['1', 'bool'],
            'same_day_enabled' => ['1', 'bool'],
            'scheduled_enabled' => ['1', 'bool'],
            'pay_at_pickup_enabled' => ['1', 'bool'],
            'manual_prepaid_enabled' => ['1', 'bool'],
            'online_payment_enabled' => ['0', 'bool'],
            'pickup_minimum_cents' => ['0', 'int'],
            'delivery_minimum_cents' => ['12500', 'int'],
            'extended_delivery_minimum_cents' => ['25000', 'int'],
            'delivery_fee_cents' => ['0', 'int'],
            'service_areas' => ['Nassau County, Suffolk County, Queens', 'string'],
            'extended_areas' => ['Hamptons, Long Beach, Queens', 'string'],
            'hours' => ['Every day, 10:00 AM - 11:00 PM', 'string'],
            'pickup_address' => ['', 'string'],
            'license_number' => ['Pending owner configuration', 'string'],
            'announcement' => ['New July menu is live. Availability is confirmed after checkout.', 'string'],
            'required_warning' => ['For use only by persons 21 years of age and older. Keep out of reach of children and pets. Consume responsibly.', 'string'],
        ];
        $stmt = $pdo->prepare('INSERT OR IGNORE INTO settings (key, value, type) VALUES (?, ?, ?)');
        foreach ($settings as $key => [$value, $type]) {
            $stmt->execute([$key, $value, $type]);
        }

        if ((int) $pdo->query('SELECT COUNT(*) FROM categories')->fetchColumn() === 0) {
            $categories = [
                ['Flower', 'flower', 10], ['Vapes', 'vapes', 20], ['Edibles', 'edibles', 30],
                ['Concentrates', 'concentrates', 40], ['Pre-Rolls', 'pre-rolls', 50],
            ];
            $stmt = $pdo->prepare('INSERT INTO categories (name, slug, position) VALUES (?, ?, ?)');
            foreach ($categories as $category) {
                $stmt->execute($category);
            }
        }

        if ((int) $pdo->query('SELECT COUNT(*) FROM promotions')->fetchColumn() === 0) {
            $stmt = $pdo->prepare('INSERT INTO promotions (title, description, active, position) VALUES (?, ?, 1, ?)');
            $stmt->execute(['Happy Hour', 'Tuesdays and Thursdays from 12 PM to 1 PM. Eligible orders save 20%.', 10]);
            $stmt->execute(['Next-day delivery', 'Schedule eligible orders for the next day and save 10%.', 20]);
            $stmt->execute(['Limited release', '710 Close Friends Thumbprints - 2g bucket, limited availability.', 30]);
        }

        if ((int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn() > 0) {
            return;
        }

        $categoryIds = [];
        foreach (Database::all('SELECT id, slug FROM categories') as $row) {
            $categoryIds[$row['slug']] = (int) $row['id'];
        }

        $products = self::products();
        $productStmt = $pdo->prepare(
            'INSERT INTO products (category_id, name, brand, slug, description, image_path, strain_type, potency, featured) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $variantStmt = $pdo->prepare(
            'INSERT INTO product_variants (product_id, label, price_cents, sale_price_cents, flavors, position) VALUES (?, ?, ?, ?, ?, ?)'
        );
        foreach ($products as $index => $product) {
            [$category, $name, $brand, $description, $image, $strain, $potency, $featured, $variants] = $product;
            $image = preg_replace('/\.png$/', '.webp', $image) ?? $image;
            $slug = self::slug($brand . '-' . $name);
            $productStmt->execute([
                $categoryIds[$category], $name, $brand, $slug, $description, $image, $strain, $potency, $featured ? 1 : 0,
            ]);
            $productId = (int) $pdo->lastInsertId();
            foreach ($variants as $position => $variant) {
                [$label, $price, $sale, $flavors] = $variant;
                $variantStmt->execute([$productId, $label, $price, $sale, $flavors, $position]);
            }
        }
    }

    private static function products(): array
    {
        return [
            ['concentrates','Crybaby Trios','Crybaby','Three flavors per jar: diamonds, badder and resin.','uploads/seed/p02-02.png',null,'3.5g',1,[['3.5g jar',6000,5000,'Ask when ordering']]],
            ['concentrates','Live Resin','Raw Garden','Single-gram live resin concentrate.','uploads/seed/p02-03.png',null,'1g',1,[['1g',5000,4000,'Ask when ordering']]],
            ['concentrates','Persy Rosin','710 Labs','Persy rosin offered as badder or sauce.','uploads/seed/p02-04.png',null,'1g',1,[['1g',8000,7000,'Ask when ordering']]],
            ['concentrates','Diamonds or Live Resin','Stiiizy','Premium concentrate in rotating flavors.','uploads/seed/p02-05.png',null,'1g',0,[['1g',5000,4000,'Mochi Gelato, Banana Sundae, White Fire, Acai Berry, Rainbow Cake, Grape Gelato, Papaya Punch']]],
            ['concentrates','Rosin Bucket','Third Shift Resin','Small-batch rosin bucket.','uploads/seed/p03-02.png',null,'1g',0,[['1g',10000,8000,'Ask when ordering']]],
            ['concentrates','Live Resin','Whole Melts Extracts','Live resin packed in-house with bulk sizes available.','uploads/seed/p03-04.png',null,null,0,[['1g',1500,null,'Ask when ordering'],['3.5g',4000,null,'Ask when ordering'],['7g',7000,null,'Ask when ordering'],['14g',13000,null,'Ask when ordering'],['1 oz',20000,null,'Ask when ordering']]],

            ['vapes','Muha Med Collab','Cookies','Two-gram live resin disposable.','uploads/seed/p04-02.png',null,'2g',1,[['2g disposable',7000,6000,'Gary Payton, Tequila Sunrise, Habibi, Blue Zlushie, Cereal Milk']]],
            ['vapes','Liquid Diamonds Disposable','Puff LA','Two-gram disposable with rotating flavors.','uploads/seed/p04-03.png',null,'2g',1,[['Single',6000,5000,'Lemon Headz, Cherry Cheesecake, Florida Pie, Sour Razz, Grape Jelly, Mango Chews'],['3 pack',15000,12000,'Mix available flavors']]],
            ['vapes','Super Switch','Sluggers','Three-flavor live diamond disposable.','uploads/seed/p04-04.png',null,'3g',1,[['3g disposable',6000,5000,'NYC Diesel / Mojito / Bubble Bath, Mango Sherb / Peach Dreams / Rainbow Runtz']]],
            ['vapes','V5 Switch','Boutiq','Three-chamber vape with digital display.','uploads/seed/p04-05.png',null,'2g',0,[['Single',10000,6000,'Sativa, Hybrid or Indica chamber sets'],['3 pack',18000,15000,'Mix available sets']]],
            ['vapes','Live Resin and Badder Disposable','Fryd','Three-gram disposable packaged with gummies.','uploads/seed/p05-02.png',null,'3g',0,[['3g disposable',7000,6000,'Bubblegum Blaster, Cosmic Cherry Cola, Solar Slush, Plasma Punch, Astro Apple']]],
            ['vapes','Quattro Vape','Sherbinski','Two-flavor live resin vape.','uploads/seed/p05-03.png',null,'2g',0,[['2g disposable',10000,6000,'No Signal x White Mochi, XJ13 x White Cherry, Lemon Taffy x Strawberry Mochi']]],
            ['vapes','Dabbar X','Dabwoods','Two-flavor live diamond switch disposable.','uploads/seed/p05-04.png',null,null,0,[['Disposable',6000,5000,'Mango Pop, Butter Cream Chem, Forbidden Octane, Blue Dream, Lemon Cherry Gelato']]],
            ['vapes','Diamond Disposable','Gas Factory','Three-gram diamond disposable.','uploads/seed/p05-05.png',null,'3g',0,[['Single',6000,5000,'Afgoo, Biscotti Sundae, Hindu Kush, Black Russian, Durban Poison'],['3 pack',15000,12000,'Mix available flavors']]],

            ['edibles','Gummy Cubes','Future','High-potency gummy cubes in rotating fruit flavors.','uploads/seed/p06-02.png',null,'3000mg',1,[['3000mg pack',6000,4500,'Passion Fruit, Fruit Punch, Peach, Strawberry, Apple']]],
            ['edibles','Live Resin Gummies','Bursts by Sauce','Forty pieces with 20mg per piece.','uploads/seed/p06-03.png',null,'800mg',1,[['40-piece pack',4000,3000,'Blue Raspberry, Juicy Watermelon, Sour Apple']]],
            ['edibles','Infused Gummy Belts','Flav','Ten gummy belts with 100mg per piece.','uploads/seed/p06-04.png',null,'1000mg',0,[['10-piece pack',4000,3000,'Sativa, Hybrid, Indica']]],
            ['edibles','Mixed Gummy Bag','Piff Stix','Mixed gummy bag.','uploads/seed/p06-05.png',null,'1500mg',0,[['Mixed bag',4000,3000,'Mixed flavors']]],
            ['edibles','Belgian Chocolate Bar','Boss Bar','Infused chocolate bar made with Belgian chocolate.','uploads/seed/p07-02.png',null,'500mg',0,[['Chocolate bar',5000,4000,'Snickahz, Peanut Buttahcup, Chocolate Waferz, Cookiez N Cream, S’morez']]],
            ['edibles','Gummies','Stiiizy','Ten pieces with 10mg per piece.','uploads/seed/p07-03.png',null,'100mg',0,[['10-piece pack',3000,2500,'Sativa, Hybrid, Indica']]],
            ['edibles','Gummies','Kush Collective','Ten pieces with 10mg per piece.','uploads/seed/p07-04.png',null,'100mg',0,[['10-piece pack',3000,2000,'Tropical Sunrise, SunBreaker Punch']]],
            ['edibles','Gummies','710 Labs / Camino','Low-dose gummies in effect-focused flavors.','uploads/seed/p07-05.png',null,'100mg',0,[['10-piece pack',3000,2500,'Midnight Blueberry, Raspberry Lemonade, Blood Orange, Mango Serenity, Wild Berry']]],
            ['edibles','Rosin Infused Gummies','Smashed','Twenty pieces with 500mg per piece.','uploads/seed/p08-03.png',null,'10,000mg',0,[['20-piece pack',10000,8000,'Peach, Green Apple Rings']]],

            ['flower','Laser Gun','Connected Labs','Premium indoor flower. Eighths are packed at approximately 3.6-4.0g.','uploads/seed/p09-02.png','Hybrid',null,1,[['3.5g',5000,null,''],['7g',9000,null,''],['14g',16000,null,''],['1 oz',30000,null,'']]],
            ['flower','Biscotti','Connected Labs','Rich, dessert-forward premium flower.','uploads/seed/p09-03.png','Indica',null,1,[['3.5g',5000,null,''],['7g',9000,null,''],['14g',16000,null,''],['1 oz',30000,null,'']]],
            ['flower','Gelonade','House Flower','Citrus-forward flower.','uploads/seed/p09-04.png','Sativa',null,1,[['3.5g',4000,null,''],['7g',7000,null,''],['14g',13000,null,''],['1 oz',25000,null,'']]],
            ['flower','Orange Starburst','House Flower','Fruit-forward indoor flower.','uploads/seed/p09-05.png','Hybrid',null,0,[['3.5g',4000,null,''],['7g',7000,null,''],['14g',13000,null,''],['1 oz',25000,null,'']]],
            ['flower','Christmas Cookie','House Flower','Premium seasonal cultivar.','uploads/seed/p09-06.png','Hybrid',null,0,[['3.5g',5000,null,''],['7g',9000,null,''],['14g',16000,null,''],['1 oz',30000,null,'']]],
            ['flower','Snow Bud','House Flower','Premium indoor flower.','uploads/seed/p09-07.png','Indica',null,0,[['3.5g',5000,null,''],['7g',10000,null,''],['14g',20000,null,''],['1 oz',35000,null,'']]],
            ['flower','Grapefruit Chem','House Flower','Citrus and fuel cultivar.','uploads/seed/p09-08.png','Hybrid',null,0,[['3.5g',4000,null,''],['7g',7000,null,''],['14g',13000,null,''],['1 oz',25000,null,'']]],
            ['flower','Life Alert','House Flower','Indoor flower with rotating availability.','uploads/seed/p09-09.png','Hybrid',null,0,[['3.5g',5000,null,''],['7g',9000,null,''],['14g',16000,null,''],['1 oz',30000,null,'']]],
            ['flower','Purple Zoo','House Flower','Value-tier flower.','uploads/seed/p09-10.png','Hybrid',null,0,[['3.5g',3000,null,''],['7g',6000,null,''],['14g',10000,null,''],['1 oz',17500,null,'']]],
            ['flower','ZOAP','Connected Labs','Premium indoor cultivar.','uploads/seed/p09-11.png','Hybrid',null,0,[['3.5g',5000,null,''],['7g',9000,null,''],['14g',16000,null,''],['1 oz',30000,null,'']]],
            ['flower','Gastro Pop','House Flower','Premium flower with sweet and fuel notes.','uploads/seed/p10-02.png','Hybrid',null,0,[['3.5g',5000,null,''],['7g',9000,null,''],['14g',16000,null,''],['1 oz',30000,null,'']]],
            ['flower','Sweet Tea','House Flower','Bright sativa-leaning flower.','uploads/seed/p10-03.png','Sativa',null,0,[['3.5g',4000,null,''],['7g',7000,null,''],['14g',13000,null,''],['1 oz',25000,null,'']]],
            ['flower','Uptown Haze','House Flower','Classic haze profile.','uploads/seed/p10-04.png','Sativa',null,0,[['3.5g',5000,null,''],['7g',9000,null,''],['14g',16000,null,''],['1 oz',30000,null,'']]],
            ['flower','Lemon Cherry Zkittles','House Flower','Value-tier fruit-forward cultivar.','uploads/seed/p10-10.png','Hybrid',null,0,[['3.5g',3000,null,''],['7g',6000,null,''],['14g',10000,null,''],['1 oz',17500,null,'']]],

            ['pre-rolls','Diamond Infused 3 Pack','Raw Garden','Three diamond-infused pre-rolls.','uploads/seed/p11-02.png',null,'3 pack',1,[['3 pack',5000,4000,'Cherry Limeade, Ohsa Kush, Lemon Berry Cookie']]],
            ['pre-rolls','Torpedo','TKO Extracts','THCA, live resin and ice-water-hash infused pre-roll.','uploads/seed/p11-03.png',null,'2g',0,[['2g pre-roll',6000,5000,'Rainbow Zlushie, Strawberry Bubble Gum, Strawnana, French Toast']]],
            ['pre-rolls','JUICED Pre-Rolls','Sluggers','Five joints infused with diamonds and hash.','uploads/seed/p11-04.png',null,'5 pack',0,[['5 pack',6000,5000,'Hurricane SZN, Banana Blueberry']]],
            ['pre-rolls','Diamond + Bubble Hash Pre-Rolls','Puff LA','Seven infused joints with a flavor booster.','uploads/seed/p11-05.png',null,'7 pack',0,[['7 pack',6000,5000,'Purple Drank, Rainbow Chewz, Cherry Slush, Kura Shige, Blue Zlurpee']]],
            ['pre-rolls','Baby Pre-Rolls','Heavy Heads','Fourteen half-gram indoor-flower pre-rolls.','uploads/seed/p12-02.png',null,'7g total',0,[['14 pack',10000,8000,'Electric Cookie, Bluetopia, more available']]],
            ['pre-rolls','40s Blunts','Stiiizy','Five infused half-gram blunts.','uploads/seed/p12-03.png',null,'5 pack',0,[['5 pack',6000,5000,'Ask when ordering']]],
            ['pre-rolls','In-House Hand Roll','House','Hand-rolled joint.','uploads/seed/p12-05.png',null,'1.25g',0,[['Single',1500,1000,'Rotating flower']]],
            ['pre-rolls','Liquid Diamond + Rosin Baby Jeeter','Jeeter','Five personal infused pre-rolls.','uploads/seed/p13-02.png',null,'5 pack',1,[['5 pack',6000,5000,'Maui Wowie, Mimosa, Grapefruit Romulan, Acapulco Gold, Pina Colada']]],
            ['pre-rolls','Hand-Rolled Hash Hole','Super Dope','1.5g flower with .5g rosin.','uploads/seed/p14-02.png',null,'2g total',1,[['Single',8000,6000,'Party Popperz, Lemon Popperz, Lychee Popperz, Cherry Popperz, Mega Z Dark']]],
        ];
    }

    private static function slug(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        return trim($value, '-');
    }
}
