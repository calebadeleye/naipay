<?php

declare(strict_types=1);

namespace App\Domains\Businesses\Database\Seeders;

use App\Domains\Businesses\Enums\CategoryStatus;
use App\Domains\Businesses\Models\BusinessCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The controlled vocabulary of business categories.
 *
 * Reference data, not sample data: it runs on every deploy. Idempotent, and
 * matched on slug so renaming a category's display name never creates a
 * duplicate or orphans the businesses filed under it.
 *
 * Existing categories are never deactivated by a reseed. Withdrawing one from
 * use is an administrative decision recorded in the audit trail, and a deploy
 * must not silently undo it.
 */
final class BusinessCategorySeeder extends Seeder
{
    /**
     * Parent categories, in the order they appear in the picker, each with its
     * subcategories.
     *
     * Subcategories exist only where the distinction changes how a business is
     * assessed — a poultry farm and a crop farm carry different risk and cash
     * flow — rather than for the sake of depth.
     *
     * @var array<string, array<int, string>>
     */
    private const CATEGORIES = [
        'Agriculture and Farming' => ['Crop Farming', 'Livestock and Poultry', 'Fishery and Aquaculture', 'Agricultural Inputs and Equipment'],
        'Agricultural Processing' => ['Grain Milling', 'Oil Processing', 'Dairy Processing', 'Packaging and Preservation'],
        'Livestock and Poultry' => [],
        'Food and Beverages' => ['Food Production', 'Beverage Production', 'Bakery and Confectionery', 'Food Distribution'],
        'Restaurants and Catering' => ['Restaurant', 'Fast Food and Takeaway', 'Catering Services', 'Bar and Lounge'],
        'Retail and General Trading' => ['Supermarket and Provisions', 'Open Market Trading', 'Kiosk and Convenience Store', 'General Merchandise'],
        'Wholesale and Distribution' => ['Food and Beverage Distribution', 'Consumer Goods Distribution', 'Building Materials Distribution'],
        'Fashion and Clothing' => ['Tailoring and Dressmaking', 'Clothing Retail', 'Footwear and Accessories', 'Textile Trading'],
        'Beauty and Personal Care' => ['Hairdressing and Barbering', 'Cosmetics Retail', 'Spa and Wellness'],
        'Health and Pharmaceuticals' => ['Pharmacy and Patent Medicine', 'Clinic and Medical Services', 'Diagnostic and Laboratory', 'Medical Supplies'],
        'Education and Training' => ['Nursery and Primary School', 'Secondary School', 'Vocational Training', 'Tutoring and Coaching'],
        'Professional Services' => ['Legal Services', 'Accounting and Audit', 'Consulting', 'Human Resources and Recruitment'],
        'Technology and Software' => ['Software Development', 'IT Support and Services', 'Computer and Device Retail', 'Digital Services'],
        'Telecommunications' => ['Airtime and Data Sales', 'Telecom Equipment', 'Network Services'],
        'Transportation and Logistics' => ['Passenger Transport', 'Haulage and Freight', 'Courier and Delivery', 'Vehicle Hire'],
        'Automotive Services' => ['Vehicle Repair and Servicing', 'Spare Parts Trading', 'Vehicle Sales', 'Car Wash'],
        'Construction and Building Materials' => ['Building Contracting', 'Cement and Blocks', 'Tiles, Paint and Finishing', 'Plumbing and Fittings'],
        'Real Estate' => ['Property Sales', 'Property Management', 'Estate Agency'],
        'Hospitality and Tourism' => ['Hotel and Guest House', 'Travel Agency', 'Tour Services'],
        'Entertainment and Media' => ['Music and Performance', 'Film and Video Production', 'Broadcasting', 'Digital Media'],
        'Printing and Publishing' => ['Commercial Printing', 'Branding and Signage', 'Publishing and Bookselling'],
        'Photography and Videography' => [],
        'Manufacturing' => ['Plastics and Packaging', 'Metal Fabrication', 'Furniture Manufacturing', 'Chemicals and Cosmetics Manufacturing'],
        'Furniture and Interior Design' => ['Furniture Retail', 'Carpentry and Joinery', 'Interior Decoration'],
        'Electrical and Electronics' => ['Electronics Retail', 'Electrical Installation', 'Appliance Repair'],
        'Repair and Maintenance' => ['Generator Repair', 'Phone and Device Repair', 'General Maintenance'],
        'Artisans and Skilled Trades' => ['Welding and Fabrication', 'Masonry', 'Painting and Decorating', 'Shoemaking and Leatherwork'],
        'Financial Services' => ['Bureau de Change', 'Agency Banking and POS', 'Cooperative and Thrift', 'Financial Advisory'],
        'Insurance Services' => [],
        'E-commerce' => ['Online Retail', 'Marketplace Trading', 'Social Commerce'],
        'Import and Export' => ['Import Trading', 'Export Trading', 'Clearing and Forwarding'],
        'Oil and Gas Services' => ['Fuel Retail', 'Cooking Gas Retail', 'Oilfield Services'],
        'Renewable Energy' => ['Solar Sales and Installation', 'Inverter and Battery Services'],
        'Security Services' => ['Guard Services', 'Security Equipment and CCTV'],
        'Cleaning Services' => ['Commercial Cleaning', 'Laundry and Dry Cleaning', 'Waste Management'],
        'Event Management' => ['Event Planning', 'Event Rentals and Decoration', 'Sound and Lighting'],
        'Sports and Fitness' => ['Gym and Fitness Centre', 'Sports Equipment Retail', 'Sports Academy'],
        'Home and Household Services' => ['Domestic Services', 'Home Repairs', 'Household Goods Retail'],
        'Religious Organisations' => [],
        'Non-Profit and Social Enterprise' => [],
        'Other Approved Business Category' => [],
    ];

    public function run(): void
    {
        DB::transaction(function (): void {
            $order = 0;

            foreach (self::CATEGORIES as $parentName => $children) {
                $order += 10;

                $parent = $this->upsert($parentName, null, $order);

                $childOrder = 0;

                foreach ($children as $childName) {
                    $childOrder += 10;

                    $this->upsert($childName, $parent->id, $childOrder);
                }
            }
        });
    }

    private function upsert(string $name, ?int $parentId, int $order): BusinessCategory
    {
        // Slug is scoped to the parent: "Livestock and Poultry" exists both as
        // a top-level category and beneath Agriculture and Farming, and the two
        // must not collide.
        $slug = $parentId === null
            ? Str::slug($name)
            : Str::slug($name).'-'.$parentId;

        $category = BusinessCategory::query()->firstOrNew(['slug' => $slug]);

        $category->name = $name;
        $category->parent_id = $parentId;
        $category->display_order = $order;

        // Status is set only on first creation. A category an administrator
        // deliberately withdrew must not be silently reactivated by a deploy.
        if (! $category->exists) {
            $category->status = CategoryStatus::Active;
        }

        $category->save();

        return $category;
    }
}
