<?php

$string['blockname'] = 'Course copy';
$string['coursecopysettings'] = 'Course copy block settings';
$string['makethiscourseamaster'] = 'Make this course a master';
$string['makethiscourseachild'] = 'Make this course a child';
$string['thiscourseisamaster'] = 'This course is a course copy master.';
$string['thiscourseisachildof'] = 'This course is a course copy child of master course';
$string['relinquishmasterstatus'] = 'Relinquish course copy master status';
$string['relinquishchildstatus'] = 'Relinquish course copy child status';
$string['confirmaction'] = "Please confirm your action";
$string['removemaster'] = 'Remove course copy master';
$string['proceed'] = 'Proceed';
$string['confirmremovalofmasterstatus'] = 'You are about to remove a course from being a course copy master. This will remove all children of this master as well as any past or prior push records. The log of copied course modules will remain untouched. Please confirm your request to remove this course as a master.';
$string['masterhasnochildren'] = 'This master has no child courses';
$string['addachildcourse'] = 'Add a new child course to a master';
$string['chooseamastercourse'] = 'Please select a master course';
$string['confirmremovalofchildstatus'] = 'You are about to remove a course from being a course copy child. This will halt all pending assessment push requests for this child. A log of all the pushes that have been performed on this course will remain intacted. Please confirm your request to remove this course as a child before continuing.';
$string['removechild'] = 'Remove this course copy child';
$string['pushcoursemodule'] = 'Push a course module';
$string['createpush'] = 'Create a new push';
$string['descriptionforpush'] = 'Why is this push occuring?';
$string['pushnow'] = 'Push as soon as possible';
$string['pushatthistime'] = 'Postpone push until this date';
$string['childrentopushto'] = 'Child courses to push to';
$string['pushassessment'] = 'Push course module';
$string['copygrades'] = 'Copy grades when applicable';
$string['requirementcheckfailedfor'] = 'Requirement check failed for';
$string['thereareopenattemptsthatneedtobecompleted'] = 'There are open attempts that need to be completed';
$string['hasanopenattemptonthisquiz'] = 'has an open attempt on this quiz.';
$string['hasworkreadytobegraded'] = 'has work ready to be graded.';
$string['coursemoduleswithsamename'] = 'This push is being blocked because there are two course modules with the same name in this course.';
$string['coursecopynotification'] = 'Course copy notification';
$string['coursecopydescription'] = "These settings configure how the course copy block behaves when a course module is copied from one course to another.";
$string['transfergrades'] = "Transfer grades";
$string['transfergradesdescription'] = "If checked, the course copy block will copy grades from the course module that matches in the destination course. Only if replacing.";
$string['replace'] = 'Deprecate and replace old course moudle';
$string['replacedescription'] = htmlspecialchars('Hides and prepends "[Deprecated]" to the course module instance name with a matching name in the destination course.');
