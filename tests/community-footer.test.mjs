import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import { communityDestinations, safeCommunityUrl, renderCommunityFooter } from '../dist/assets/community-footer.js';

test('unconfigured Tally and Ko-fi links stay hidden (never send commuters to provider homepages)',()=>{
  assert.deepEqual(communityDestinations,{feedback:'',coffee:''});
  const markup=renderCommunityFooter();
  assert.doesNotMatch(markup,/<nav|<a\b|https:\/\//);
  assert.match(markup,/Designed for Sydney commuters by Y\.T\. Ng/);
  assert.match(markup,/<footer class="footer">/);
});

test('valid owner-supplied Tally and Ko-fi URLs are exactly linked with safe outbound attributes',()=>{
  const html=renderCommunityFooter({feedback:'https://tally.so/r/Ab3D2',coffee:'https://ko-fi.com/sydstationalert'});
  assert.match(html,/<nav class="footer-community" aria-label="Feedback and support">/);
  assert.match(html,/href="https:\/\/tally\.so\/r\/Ab3D2"/);
  assert.match(html,/href="https:\/\/ko-fi\.com\/sydstationalert"/);
  assert.match(html,/Feedback &amp; report an issue/);
  assert.match(html,/Buy me a coffee/);
  assert.equal((html.match(/target="_blank" rel="noopener noreferrer" referrerpolicy="no-referrer"/g)||[]).length,2);
  assert.equal((html.match(/<a\b/g)||[]).length,2);
});

test('each link can be enabled independently without blank or broken links',()=>{
  const onlyFeedback=renderCommunityFooter({feedback:'https://tally.so/r/abc123',coffee:''});
  assert.match(onlyFeedback,/tally.so/);assert.doesNotMatch(onlyFeedback,/ko-fi|footer-separator/);
  const onlyCoffee=renderCommunityFooter({feedback:'',coffee:'https://ko-fi.com/my_page'});
  assert.match(onlyCoffee,/ko-fi.com/);assert.doesNotMatch(onlyCoffee,/tally.so|footer-separator/);
});

test('unsafe and forged external destinations are rejected, not rendered',()=>{
  const blocked=[
    '','http://tally.so/r/abcdef','https://evil.tally.so/r/abcdef',
    'https://tally.so.evil.net/r/abcdef','https://evil.com/feedback',
    'https://tally.so@evil.com/r/abcdef','https://user:pass@tally.so/r/abcdef',
    'https://tally.so:8080/r/abcdef','javascript:alert(1)',
    'https://tally.so/r/abc?email=person@example.com',
    'https://tally.so/r/abc#id','https://tally.so/',
    'https://tally.so/r/a%22onclick=alert(1)'
  ];
  for(const candidate of blocked)assert.equal(safeCommunityUrl(candidate,'tally'),null,candidate);
  const blockedCoffee=[
    '','http://ko-fi.com/test','https://ko-fi.com.evil.net/me',
    'https://ko-fi.com@evil.net/me','https://ko-fi.com/home/pricing',
    'https://ko-fi.com/me?name=passenger','https://ko-fi.com/me#extra',
    'https://ko-fi.com/a','https://ko-fi.com/creator/checkout',
    'https://ko-fi.com:444/myhandle','https://evil.com/myhandle'
  ];
  for(const candidate of blockedCoffee)assert.equal(safeCommunityUrl(candidate,'kofi'),null,candidate);
});

test('only two valid external destinations can appear; bad provider does not affect the other',()=>{
  const html=renderCommunityFooter({feedback:'https://fake.tally.so/r/abc123',coffee:'https://ko-fi.com/railcoffee'});
  assert.equal((html.match(/<a\b/g)||[]).length,1);
  assert.doesNotMatch(html,/fake.tally.so/);
  assert.match(html,/href="https:\/\/ko-fi.com\/railcoffee"/);
  const injected=renderCommunityFooter({feedback:'https://tally.so/r/ok123" onclick="alert(1)',coffee:'javascript:alert(1)'});
  assert.doesNotMatch(injected,/<a\b|onclick/);
});

test('community footer is used in both initial setup and selected journey without modifying train routing',()=>{
  const main=fs.readFileSync('src/main.ts','utf8');
  const css=fs.readFileSync('public/styles.css','utf8');
  assert.match(main,/import \{ renderCommunityFooter \} from '\.\/community-footer\.js';/);
  assert.match(main,/const footer=renderCommunityFooter\(\);/);
  assert.ok((main.match(/\$\{footer\}<\/main>/g)||[]).length>=2);
  assert.match(css,/\.footer-community\s*\{[^}]*flex-wrap:\s*wrap/);
  assert.match(css,/\.footer-community a:focus-visible/);
  assert.doesNotMatch(main,/tally\.so\/|ko-fi\.com\//);
});
