<?php

namespace App\Support;

/**
 * The fixed list of editable text blocks on the public website (Home/About/Contact), and the copy they
 * fall back to when the owner hasn't changed anything yet. This is the single source of truth for both
 * the admin editor (Settings > Website Text) and the public pages themselves -- a field that exists here
 * can be edited; nothing else on the page can be (nav labels, button destinations, and the footer are
 * fixed structure, not content).
 */
class SiteContentSchema
{
    /** @return array<string, array<string, array{label: string, type: 'text'|'textarea', default: string}>> */
    public static function pages(): array
    {
        return [
            'home' => [
                'hero_eyebrow' => ['label' => 'Small label above the headline', 'type' => 'text', 'default' => 'Ashewa Meda, Burayu · Est. 2008 E.C.'],
                'hero_title' => ['label' => 'Main headline', 'type' => 'text', 'default' => "Where Every Child's Potential Finds Its Horizon"],
                'hero_subtitle' => ['label' => 'Headline description', 'type' => 'textarea', 'default' => "School of Paradise Hills brings together education, creativity, and character on one spacious, well-kept campus just west of Addis Ababa — a place built for how children actually grow, step by step, into who they'll become."],
                'cta_primary_label' => ['label' => 'First button label', 'type' => 'text', 'default' => 'Discover Our School'],
                'cta_secondary_label' => ['label' => 'Second button label', 'type' => 'text', 'default' => 'Explore Admissions'],
                'about_title' => ['label' => '"About Us" section title', 'type' => 'text', 'default' => 'A campus built for how children grow'],
                'about_body' => ['label' => '"About Us" section text', 'type' => 'textarea', 'default' => 'School of Paradise Hills was established in 2008 E.C. in Ashewa Meda, Burayu — one of the fastest-growing towns in the Oromia Special Zone, just 20 kilometers west of Addis Ababa. Our own well-facilitated, clean campus is conducive for kids schooling, with room to learn, play, and grow safely.'],
                'pillars_title' => ['label' => '"What We Offer" section title', 'type' => 'text', 'default' => 'Academics, admissions, and school life'],
                'academics_body' => ['label' => 'Academics text', 'type' => 'textarea', 'default' => "A structured, caring classroom environment from the early years through secondary, with attention given to each child's own pace of learning."],
                'admissions_body' => ['label' => 'Admissions text', 'type' => 'textarea', 'default' => 'Families are welcome to reach out any time to ask about enrollment, visit the campus, or start the admissions conversation — see Contact below.'],
                'school_life_body' => ['label' => 'School Life text', 'type' => 'textarea', 'default' => 'A spacious, clean campus with room to learn and play, centered on our values of honesty and hard work.'],
                'contact_cta_title' => ['label' => 'Bottom section title', 'type' => 'text', 'default' => "Let's talk about your child's next chapter"],
                'contact_cta_subtitle' => ['label' => 'Bottom section text', 'type' => 'textarea', 'default' => "Reach out for admissions, a campus visit, or any question at all — we'd love to hear from you."],
            ],
            'about' => [
                'hero_title' => ['label' => 'Main headline', 'type' => 'text', 'default' => 'Our Story'],
                'hero_subtitle' => ['label' => 'Headline description', 'type' => 'textarea', 'default' => "A school built on a simple idea: every child deserves a safe, well-kept place to learn, grow, and become who they're meant to be."],
                'story_paragraph_1' => ['label' => 'Our story — paragraph 1', 'type' => 'textarea', 'default' => "School of Paradise Hills was established in 2008 E.C. (Ethiopian Calendar) in Ashewa Meda, Burayu Town — one of the fast-growing towns incorporated into the Oromia National Regional State's Special Zones. Burayu sits about 20 kilometers west of Addis Ababa, the capital city of the Federal Democratic Republic of Ethiopia and seat of the headquarters of the African Union and countless international organizations."],
                'story_paragraph_2' => ['label' => 'Our story — paragraph 2', 'type' => 'textarea', 'default' => 'Our school has its own well-facilitated, clean campus — a space that is genuinely conducive for kids schooling, with room to learn, to play, and to grow. We focus on the holistic development of every student who walks through our gates, not just what happens inside a classroom.'],
                'story_paragraph_3' => ['label' => 'Our story — paragraph 3', 'type' => 'textarea', 'default' => 'Everything we do is guided by three words, carried on our own school crest: be honest, work hard, and learn. They are simple, but we take them seriously — in the classroom, on the playground, and in how our staff treat every family who trusts us with their child.'],
                'value_1_title' => ['label' => 'First value — title', 'type' => 'text', 'default' => 'Be Honest'],
                'value_1_body' => ['label' => 'First value — text', 'type' => 'textarea', 'default' => 'Honesty is the foundation of trust — between students, teachers, and families alike.'],
                'value_2_title' => ['label' => 'Second value — title', 'type' => 'text', 'default' => 'Work Hard'],
                'value_2_body' => ['label' => 'Second value — text', 'type' => 'textarea', 'default' => 'Real growth comes from steady effort, not shortcuts. We encourage children to try, fail, and try again.'],
                'value_3_title' => ['label' => 'Third value — title', 'type' => 'text', 'default' => 'Learn'],
                'value_3_body' => ['label' => 'Third value — text', 'type' => 'textarea', 'default' => 'Curiosity and a love of learning, carried well beyond the classroom walls.'],
                'cta_title' => ['label' => 'Bottom section title', 'type' => 'text', 'default' => 'Want to see the campus for yourself?'],
                'cta_subtitle' => ['label' => 'Bottom section text', 'type' => 'textarea', 'default' => "We'd love to show you around and answer any questions about admissions."],
            ],
            'contact' => [
                'hero_title' => ['label' => 'Main headline', 'type' => 'text', 'default' => "We'd love to hear from you"],
                'hero_subtitle' => ['label' => 'Headline description', 'type' => 'textarea', 'default' => 'Questions about admissions, a campus visit, or anything else — send us a message or call us directly.'],
                'office_hours' => ['label' => 'Office hours', 'type' => 'text', 'default' => 'Monday – Friday, during the school day.'],
                'visit_description' => ['label' => 'Visit Us — address description', 'type' => 'textarea', 'default' => 'Ashewa Meda, Burayu Town, Oromia Special Zone — about 20km west of Addis Ababa.'],
            ],
        ];
    }

    public static function pageLabels(): array
    {
        return ['home' => 'Home', 'about' => 'About Us', 'contact' => 'Contact'];
    }

    /** The merged, ready-to-render content for one page: every schema field, each either the owner's
     * saved value or its fallback copy. Always returns every key -- a page template never has to guard
     * against a missing one. */
    public static function forPage(string $page): array
    {
        $fields = self::pages()[$page] ?? [];
        $saved = \App\Models\SiteContent::where('page', $page)->pluck('value', 'field_key');

        $out = [];
        foreach ($fields as $key => $def) {
            $out[$key] = $saved[$key] ?? $def['default'];
        }

        return $out;
    }
}
