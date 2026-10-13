/** SSA-11/12: community destinations are external, creator-owned pages.
 *
 * Put the user's published Tally form and Ko-fi support page URLs below after
 * verifying that those pages belong to Sydney Station Alert. Until configured,
 * no calls are made to those providers and the links are deliberately hidden.
 *
 * Do not put personal emails, payment credentials, form submission tokens,
 * journey data or user identifiers in these URLs.
 */
export const communityDestinations={
  feedback: 'https://tally.so/r/9qLvLp',
  coffee: 'https://ko-fi.com/sydstnalrt',
};

/** Do not render links to arbitrary hosts or to non-HTTPS payment/form pages. */
export function safeCommunityUrl(value:string,provider:'tally'|'kofi'):string|null{
  if(!value||typeof value!=='string')return null;
  try{
    const url=new URL(value);
    if(url.protocol!=='https:'||url.username||url.password||url.port)return null;
    if(url.search||url.hash)return null; // Don't put commuter data in third-party URLs.
    if(provider==='tally'){
      if(url.hostname!=='tally.so'||!/^\/r\/[a-zA-Z0-9]+\/?$/.test(url.pathname))return null;
    }else{
      if(url.hostname!=='ko-fi.com'||!/^\/[a-zA-Z0-9_-]{3,80}\/?$/.test(url.pathname))return null;
    }
    return url.href;
  }catch{return null;}
}

export function renderCommunityFooter(destinations=communityDestinations):string{
  const feedback=safeCommunityUrl(destinations.feedback,'tally');
  const coffee=safeCommunityUrl(destinations.coffee,'kofi');
  const links=[
    feedback?'<a href="'+feedback+'" target="_blank" rel="noopener noreferrer" referrerpolicy="no-referrer">Feedback &amp; report an issue</a>':'',
    coffee?'<a href="'+coffee+'" target="_blank" rel="noopener noreferrer" referrerpolicy="no-referrer">Buy me a coffee</a>':''
  ].filter(Boolean);
  const nav=links.length?'<nav class="footer-community" aria-label="Feedback and support">'+links.join('<span aria-hidden="true" class="footer-separator">·</span>')+'</nav>':'';
  return '<footer class="footer">'+nav+'<span class="footer-credit">Designed for Sydney commuters by Y.T. Ng</span></footer>';
}
