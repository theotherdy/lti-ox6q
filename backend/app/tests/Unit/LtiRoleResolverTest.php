<?php

namespace Tests\Unit;

use App\Services\LtiRoleResolver;
use PHPUnit\Framework\TestCase;

class LtiRoleResolverTest extends TestCase
{
    public function test_is_instructor_returns_true_for_standard_instructional_roles(): void
    {
        $resolver = new LtiRoleResolver();

        $this->assertTrue($resolver->isInstructor([
            'http://purl.imsglobal.org/vocab/lis/v2/membership#Instructor',
        ]));

        $this->assertTrue($resolver->isInstructor([
            'urn:lti:role:ims/lis/TeachingAssistant',
        ]));
    }

    public function test_is_instructor_returns_false_for_student_only_roles(): void
    {
        $resolver = new LtiRoleResolver();

        $this->assertFalse($resolver->isInstructor([
            'http://purl.imsglobal.org/vocab/lis/v2/membership#Learner',
            'urn:lti:instrole:ims/lis/Student',
        ]));
    }
}
