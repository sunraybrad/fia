<form method="post"  id="userForm" action="connect_form2.php">
<table width="100%" border="0">
  <tr>
    <td width="35%" valign="top">Your Name<strong>(*)</strong></td>
    <td width="65%" valign="top"><input type="text" value="" size="30" name="Name" id="Name" /></td>
  </tr>
  <tr>
    <td valign="top">Your Email<strong>(*)</strong></td>
    <td valign="top"><input type="text" value="" size="30" name="Email" id="Email" /></td>
  </tr>
  <tr>
    <td valign="top">Phone Number<strong>(*)</strong></td>
    <td valign="top"><input type="text" value="" size="30" name="Phone" id="Phone" /></td>
  </tr>
  <tr>
    <td valign="top">Preferred Contact<strong>(*)</strong></td>
    <td valign="top">
    <input type="radio"  name="Preferred" value="Email" id="Preferred0" class="rsform-radio" /><label for="Preferred0"> Email</label>&nbsp;&nbsp;
    <input type="radio"  name="Preferred" value="Phone" id="Preferred1" class="rsform-radio" /><label for="Preferred1"> Phone</label>
    </td>
  </tr>
  <tr>
    <td valign="top">What do you need?</td>
    <td valign="top">
    <input type="radio"  name="Concern" value="Inspection" id="Concern0" rel="inspection" /><label for="Concern0">Inspection</label>&nbsp;&nbsp;
    <input type="radio"  name="Concern" value="Appraisal" id="Concern1" rel="appraisal" /><label for="Concern1">Appraisal</label>
    </td>
  </tr>
  <tr rel="inspection">
    <td valign="top">What type of inspection do you need?</td>
    <td valign="top">
    <input type="radio"  name="Concern_type" value="Mechanical" id="Inspection_type0" /><label for="Inspection_type0">Mechanical</label><br />
    <input type="radio"  name="Concern_type" value="Tire" id="Inspection_type1" /><label for="Inspection_type1">Tire</label>
    </td>
  </tr>
  <tr rel="appraisal">
    <td valign="top">What type of appraisal do you need?</td>
    <td valign="top">
    <input type="radio"  name="Concern_type" value="Total Loss Appraisal" id="Appraisal_type0" /><label for="Appraisal_type0">Total Loss Appraisal</label><br />
    <input type="radio"  name="Concern_type" value="Diminished-Value Appraisal" id="Appraisal_type1" /><label for="Appraisal_type1">Diminished-Value Appraisal</label><br />
    <input type="radio"  name="Concern_type" value="Lease Turn-In Appraisal" id="Appraisal_type2" /><label for="Appraisal_type2">Lease Turn-In Appraisal</label><br />
    <input type="radio"  name="Concern_type" value="Appraisal for Loan" id="Appraisal_type3" /><label for="Appraisal_type3">Appraisal for Loan</label><br />
    <input type="radio"  name="Concern_type" value="Appraisal for Tax" id="Appraisal_type4" /><label for="Appraisal_type4">Appraisal for Tax</label><br />
    <input type="radio"  name="Concern_type" value="Appraisal for Customs" id="Appraisal_type5" /><label for="Appraisal_type5">Appraisal for Customs</label><br />
    <input type="radio"  name="Concern_type" value="Appraisal for Charitable Contributions" id="Appraisal_type6" /><label for="Appraisal_type6">Appraisal for Charitable Contributions</label><br />
    <input type="radio"  name="Concern_type" value="Appraisal for Marital/Business Disputes" id="Appraisal_type7" /><label for="Appraisal_type7">Appraisal for Marital/Business Disputes</label><br />
    <input type="radio"  name="Concern_type" value="Appraisal for Pre-sale/Purchase" id="Appraisal_type8" /><label for="Appraisal_type8">Appraisal for Pre-sale/Purchase</label><br />
    <input type="radio"  name="Concern_type" value="Pre-Restoration Appraisal" id="Appraisal_type9" /><label for="Appraisal_type9">Pre-Restoration Appraisal</label>
    </td>
  </tr>
  <tr>
    <td valign="top">Would you like to send us a message?</td>
    <td valign="top"><textarea cols="50" rows="5" name="Message" id="Message"></textarea></td>
  </tr>
  <tr>
    <td valign="top">Help us fight spam.</td>
    <td valign="top"><? if ($error == 'nocaptcha') { ?><div class="alert-danger">&nbsp;you must check the CAPTCHA box</div><? } ?> 
                        <div class="g-recaptcha" data-sitekey="6LdLTKIUAAAAAH6yjShL-_KvXeukOuE8OzWPo48d"></div></td>
  </tr>
  <tr>
    <td valign="top">&nbsp;</td>
    <td valign="top"><input name="Send" type="submit" value="Submit" /></td>
  </tr>
</table>
</form>