<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the store's main bag categories and their subcategories.
 */
class CategorySeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            foreach ($this->tree() as $parentOrder => $node) {
                $parent = Category::withTrashed()->updateOrCreate(
                    ['slug' => $node['slug']],
                    [
                        'parent_id' => null,
                        'name_ar' => $node['name_ar'],
                        'name_en' => $node['name_en'],
                        'description_ar' => $node['description_ar'],
                        'description_en' => $node['description_en'],
                        'sort_order' => $parentOrder,
                        'is_active' => true,
                        'deleted_at' => null,
                    ],
                );

                foreach ($node['children'] as $childOrder => $child) {
                    Category::withTrashed()->updateOrCreate(
                        ['slug' => $child['slug']],
                        [
                            'parent_id' => $parent->id,
                            'name_ar' => $child['name_ar'],
                            'name_en' => $child['name_en'],
                            'description_ar' => $child['description_ar'] ?? null,
                            'description_en' => $child['description_en'] ?? null,
                            'sort_order' => $childOrder,
                            'is_active' => true,
                            'deleted_at' => null,
                        ],
                    );
                }
            }
        });
    }

    /**
     * @return list<array{
     *     name_ar: string,
     *     name_en: string,
     *     slug: string,
     *     description_ar: string,
     *     description_en: string,
     *     children: list<array{name_ar: string, name_en: string, slug: string, description_ar?: string, description_en?: string}>
     * }>
     */
    private function tree(): array
    {
        return [
            [
                'name_ar' => 'شنط حريمي كلاسيك وفاخرة',
                'name_en' => "Classic & Luxury Women's Bags",
                'slug' => 'classic-luxury-womens-bags',
                'description_ar' => 'الشغل، المناسبات، السهرات',
                'description_en' => 'Work, Occasions, Evening Events',
                'children' => [
                    ['name_ar' => 'شنط يد', 'name_en' => 'Handbags', 'slug' => 'handbags'],
                    ['name_ar' => 'شنط كتف', 'name_en' => 'Shoulder Bags', 'slug' => 'shoulder-bags'],
                    ['name_ar' => 'كروس بودي', 'name_en' => 'Crossbody', 'slug' => 'crossbody-bags'],
                    ['name_ar' => 'كلاتش وسواريه', 'name_en' => 'Clutch & Evening', 'slug' => 'clutch-and-evening'],
                ],
            ],
            [
                'name_ar' => 'شنط كاجوال يومية',
                'name_en' => 'Daily Casual Bags',
                'slug' => 'daily-casual-bags',
                'description_ar' => 'الخروجات اليومية، الجامعة، التسوق',
                'description_en' => 'Daily Outings, University, Shopping',
                'children' => [
                    ['name_ar' => 'توت باج', 'name_en' => 'Tote Bags', 'slug' => 'tote-bags'],
                    ['name_ar' => 'شنط هوبو', 'name_en' => 'Hobo', 'slug' => 'hobo-bags'],
                    ['name_ar' => 'شنط كروس عملية', 'name_en' => 'Practical Crossbody', 'slug' => 'practical-crossbody-bags'],
                    ['name_ar' => 'ميني باج وباوتش', 'name_en' => 'Mini & Pouch', 'slug' => 'mini-and-pouch-bags'],
                ],
            ],
            [
                'name_ar' => 'شنط السفر',
                'name_en' => 'Travel Bags',
                'slug' => 'travel-bags',
                'description_ar' => 'السفر، الإجازات، رحلات العمل',
                'description_en' => 'Travel, Vacations, Business Trips',
                'children' => [
                    ['name_ar' => 'ترولي بعجل (هارد وسوفت)', 'name_en' => 'Wheeled Trolley (Hard & Soft)', 'slug' => 'wheeled-trolley-bags'],
                    ['name_ar' => 'دافل وويك إند', 'name_en' => 'Duffel & Weekend', 'slug' => 'duffel-and-weekend-bags'],
                    ['name_ar' => 'شنط كابينة طيران', 'name_en' => 'Carry-on', 'slug' => 'carry-on-cabin-bags'],
                    ['name_ar' => 'منظمات سفر', 'name_en' => 'Travel Organizers', 'slug' => 'travel-organizers'],
                ],
            ],
            [
                'name_ar' => 'شنط ظهر (باك باك)',
                'name_en' => 'Backpacks',
                'slug' => 'backpacks',
                'description_ar' => 'الشغل، الجامعة، التنقل اليومي',
                'description_en' => 'Work, University, Daily Commute',
                'children' => [
                    ['name_ar' => 'باك باك لاب توب ومكتب', 'name_en' => 'Laptop & Office Backpacks', 'slug' => 'laptop-and-office-backpacks'],
                    ['name_ar' => 'باك باك كاجوال', 'name_en' => 'Casual Backpacks', 'slug' => 'casual-backpacks'],
                    ['name_ar' => 'باك باك سفر ومقاس كابينة', 'name_en' => 'Travel & Cabin Size Backpacks', 'slug' => 'travel-and-cabin-backpacks'],
                    ['name_ar' => 'ميني باك باك', 'name_en' => 'Mini Backpacks', 'slug' => 'mini-backpacks'],
                ],
            ],
            [
                'name_ar' => 'شنط الأطفال',
                'name_en' => 'Kids Bags',
                'slug' => 'kids-bags',
                'description_ar' => 'المدرسة، الحضانة، التمارين',
                'description_en' => 'School, Nursery, Sports Practice',
                'children' => [
                    ['name_ar' => 'شنط مدرسة (ظهر وترولي)', 'name_en' => 'School Bags (Backpack & Trolley)', 'slug' => 'kids-school-bags'],
                    ['name_ar' => 'شنط حضانة', 'name_en' => 'Nursery Bags', 'slug' => 'kids-nursery-bags'],
                    ['name_ar' => 'لانش باج', 'name_en' => 'Lunch Bags', 'slug' => 'kids-lunch-bags'],
                    ['name_ar' => 'شنط نادي وأنشطة', 'name_en' => 'Club & Activity Bags', 'slug' => 'kids-club-and-activity-bags'],
                ],
            ],
            [
                'name_ar' => 'شنط هاند ميد',
                'name_en' => 'Handmade Bags',
                'slug' => 'handmade-bags',
                'description_ar' => 'الإطلالات المميزة، الصيف، الهدايا',
                'description_en' => 'Unique Looks, Summer, Gifts',
                'children' => [
                    ['name_ar' => 'شنط كروشيه ومكرمية', 'name_en' => 'Crochet & Macrame Bags', 'slug' => 'crochet-and-macrame-bags'],
                    ['name_ar' => 'شنط خرز ولولي', 'name_en' => 'Beaded & Pearl Bags', 'slug' => 'beaded-and-pearl-bags'],
                    ['name_ar' => 'شنط جلد طبيعي يدوي', 'name_en' => 'Handmade Genuine Leather Bags', 'slug' => 'handmade-leather-bags'],
                    ['name_ar' => 'شنط خوص وقماش مطرز', 'name_en' => 'Straw & Embroidered Fabric Bags', 'slug' => 'straw-and-embroidered-bags'],
                ],
            ],
        ];
    }
}
