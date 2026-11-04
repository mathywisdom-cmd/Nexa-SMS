<?php

namespace Tests\Feature;

use Tests\TestCase;

class LegalPagesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app['config']->set('app.key', 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=');
    }

    public function test_terms_page_is_public_platform_branded_and_links_to_privacy_policy()
    {
        $response = $this->get(route('terms_of_use'));

        $response->assertOk()
            ->assertSee('<title>NexaSMS by Mathias</title>', false)
            ->assertSee('TERMS &amp; CONDITIONS', false)
            ->assertSee('NexaSMS by Mathias')
            ->assertSee(route('privacy_policy', [], false), false);

        $this->assertNoLegacyTemplateReferences($response->getContent());
        $this->assertContactOptions($response->getContent());
        foreach ([
            'Acceptance of Terms', 'About NexaSMS', 'Use of the Platform', 'School Responsibilities',
            'User Accounts', 'Account Security', 'Student and School Information',
            'Academic and Administrative Records', 'Multi-School/Tenant Separation', 'Acceptable Use',
            'Prohibited Activities', 'Intellectual Property', 'School Data and User Content',
            'Platform Availability and Maintenance', 'Security', 'Third-Party Services',
            'Suspension and Termination', 'Disclaimers', 'Limitation of Liability', 'Indemnification',
            'Changes to the Terms', 'Governing Law', 'Contact Information',
        ] as $section) {
            $response->assertSee($section);
        }
    }

    public function test_privacy_page_is_public_platform_branded_currently_dated_and_links_to_terms()
    {
        $response = $this->get(route('privacy_policy'));

        $response->assertOk()
            ->assertSee('<title>NexaSMS by Mathias</title>', false)
            ->assertSee('PRIVACY POLICY')
            ->assertSee('NexaSMS by Mathias')
            ->assertSee('Effective date: '.now()->format('F j, Y'))
            ->assertSee(route('terms_of_use', [], false), false);

        $this->assertNoLegacyTemplateReferences($response->getContent());
        $this->assertContactOptions($response->getContent());
        foreach ([
            'Introduction', 'Information We May Process', 'How Information Is Collected',
            'How Information Is Used', 'School Responsibilities', 'User Accounts',
            'Student and Children’s Information', 'Academic and Administrative Information',
            'Multi-School Data Separation', 'Information Sharing and Disclosure', 'Data Security',
            'Data Retention', 'Cookies and Similar Technologies', 'Third-Party Services',
            'User and School Data Requests', 'Changes to the Privacy Policy', 'Contact Information',
        ] as $section) {
            $response->assertSee($section);
        }
    }

    private function assertNoLegacyTemplateReferences($html)
    {
        foreach ([
            'Laravel', 'Local Smoke School', 'CJ Inspired', '127.0.0.1', 'Nigeria',
            'Catholic Mercy', 'November 4, 2019', 'localhost:',
        ] as $reference) {
            $this->assertStringNotContainsString($reference, $html);
        }
    }

    private function assertContactOptions($html)
    {
        foreach ([
            'href="https://wa.me/256706526642"',
            'href="https://t.me/+256708949155"',
            'href="tel:+256207896805"',
        ] as $destination) {
            $this->assertStringContainsString($destination, $html);
        }

        foreach (['WhatsApp', 'Telegram', 'Call Us'] as $label) {
            $this->assertStringContainsString('>'.$label.'</a>', $html);
        }

        $visibleText = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        foreach ([
            '0706526642', '256706526642',
            '0708949155', '256708949155',
            '+256207896805', '256207896805',
        ] as $number) {
            $this->assertStringNotContainsString($number, $visibleText);
        }
    }
}
