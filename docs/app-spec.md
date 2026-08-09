Please generate a flashcard program to help people review vocabulary for the SAT.  The site will be vocab.lillyrosenthal.org.

Users should be able to login and see flashcards of words.  The list of words should be global.

The core function of the site is after someone logs in, they should go through flashcards.  

A user should be able to click on a word and see the definition. (maybe fade in and out quickly to turn the card, but any effect has to be quick)

For each flashcard / word:
- "Got it" or "Need More Review" (maybe buttons underneath the word).  This is marked for the user only. 
- Flag icon top right.  Toggle state (should show when looking at card).  Ajax save on click.  Flagged words are flagged for the user only.
- Stats kept based on each "Got it" or "Need more review."

CSV Upload for words with columns
- word
- definition

There should be an "order" to the words so that a user can know how many they've gone through, and the user should be able to "shuffle" the words.

The colors should be playful - I want it to be fun to use!

It should keep stats on how many words user go through so that they can feel progress.  Maybe a "score" in the top right after login.

I want this to be a PHP / MYSQL web site.  Please follow the guidelines and create a login infrastructure.  There is a similar app (in that it is php and in the style I want it) in ../portal.bronxconservatory.org

The website should be in the "www" directory.

I want it to work on the website (like the bronx conservatory website)