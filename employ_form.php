<form action="employ_form2.php" method="post" enctype="multipart/form-data" name="form1" onsubmit="event.preventDefault(); grecaptcha.execute();">
	<p><font size="2" face="Verdana, Arial, Helvetica, sans-serif"><br>
	  Fill out and submit the form below to become one of our professional team
	      of inspectors and appraisors. <font size="2">(Fields labeled in <strong>BOLD</strong> are
	      required.)</font></font></p>
	<table width="100%" border="0" cellspacing="8px" cellpadding="8px">
	  <tr>
	    <td width="29%"><div align="right"><strong><font face="Verdana, Arial, Helvetica, sans-serif"><font size="1">Full Name:</font></font></strong></div></td>
	    <td width="71%"><input name="Name" type="text" id="Name" size="40"></td>
	  </tr>
        <? if (strpos($error, 'error') !== false) { ?>
      <tr>
      <td></td>
      <td><span class="alert-danger">Name & Email are required.</span></td>
      </tr>
        <? } ?>      
	  <tr>
	    <td><div align="right"><strong><font face="Verdana, Arial, Helvetica, sans-serif"><font size="1">Address:</font></font></strong></div></td>
	    <td><input name="address" type="text" id="address" size="50"></td>
	    </tr>
	  <tr>
	    <td><div align="right"><strong><font face="Verdana, Arial, Helvetica, sans-serif"><font size="1">City:</font></font></strong></div></td>
	    <td><font size="1" face="Verdana, Arial, Helvetica, sans-serif">
	      <input name="city" type="text" id="city" size="20">
	      <strong>&nbsp;State:</strong>	      
	      <input name="state" type="text" id="state" size="4">
	      &nbsp;<strong>Zip:
	      </strong>
	      <input name="zip" type="text" id="zip" size="12">
	    </font></td>
	    </tr>
	  <tr>
	    <td><div align="right"><strong><font size="1" face="Verdana, Arial, Helvetica, sans-serif">eMail Address:</font></strong></div></td>
	    <td><input name="email" type="text" id="email" size="50"></td>
	    </tr>
        <? if (strpos($error, 'error') !== false) { ?>
      <tr>
      <td></td>
      <td><span class="alert-danger">Name & Email are required.</span></td>
      </tr>
        <? } ?> 
	  <tr>
	    <td><div align="right"><font face="Verdana, Arial, Helvetica, sans-serif"><font size="1">Home
	            Phone:</font></font></div></td>
	    <td><input name="phonehm" type="text" id="phonehm" size="25"></td>
	    </tr>
	  <tr>
	    <td><div align="right"><font face="Verdana, Arial, Helvetica, sans-serif"><font size="1">Cell
	            Phone:</font></font></div></td>
	    <td><input name="phonecell" type="text" id="phonecell" size="25"></td>
	    </tr>
	  <tr>
	    <td><div align="right"><font face="Verdana, Arial, Helvetica, sans-serif"><font size="1">Pager:</font></font></div></td>
	    <td><input name="phonepage" type="text" id="phonepage" size="25"></td>
	    </tr>
	  <tr>
	    <td><div align="right"><font face="Verdana, Arial, Helvetica, sans-serif"><font size="1"><strong>Office/Day
	            Phone:</strong></font></font></div></td>
	    <td><input name="phoneoff" type="text" id="phoneoff" size="25"></td>
	    </tr>
	  <!-- <tr>
	    <td valign="top"><div align="right"><font face="Verdana, Arial, Helvetica, sans-serif"><font size="1">Attach a Resume:</font></font></div></td>
	    <td valign="top"><input name="resumefile" type="file" id="resumefile" size="35">
	      <br>
	      <font color="#CC0000" size="1" face="Verdana, Arial, Helvetica, sans-serif">must be in format: PDF, DOC, DOCX, TXT, ODF</font></td>
	    </tr>
      -->
	  <tr>
	    <td valign="top"><div align="right"><font face="Verdana, Arial, Helvetica, sans-serif"><font size="1"><strong>Education<br>
	      (including Technical<br>
          school or training):</strong></font></font></div></td>
	    <td valign="top"><textarea name="education" cols="50" rows="4" id="education"></textarea></td>
	    </tr>
	  <tr>
	    <td valign="top"><div align="right"><font face="Verdana, Arial, Helvetica, sans-serif"><font size="1">Certifications or Licensing:</font></font></div></td>
	    <td nowrap><font size="1" face="Verdana, Arial, Helvetica, sans-serif">
	      <input name="cert1" type="text" id="cert1" size="30">&nbsp;Exp Date:&nbsp;<input name="certdate1" type="text" id="certdate1" size="9"></br>
            <input name="cert12" type="text" id="cert12" size="30">&nbsp;Exp Date:&nbsp;<input name="certdate12" type="text" id="certdate12" size="9"></br>
            <input name="cert13" type="text" id="cert13" size="30">&nbsp;Exp Date:&nbsp;<input name="certdate13" type="text" id="certdate13" size="9"></br>
            <input name="cert14" type="text" id="cert14" size="30">&nbsp;Exp Date:&nbsp;<input name="certdate14" type="text" id="certdate14" size="9">
            </font></td>
	    </tr>
	  <tr valign="top">
	    <td><div align="right"><font face="Verdana, Arial, Helvetica, sans-serif"><font size="1">Describe
	            Your Automotive
            Knowledge or Experience:</font></font></div></td>
	    <td><textarea name="knowledge" cols="50" rows="4" id="knowledge"></textarea></td>
	  </tr>
	  <tr>
	    <td><div align="right"><font face="Verdana, Arial, Helvetica, sans-serif"><font size="1">Camera and Internet:</font></font></div></td>
	    <td><font size="2" face="Verdana, Arial, Helvetica, sans-serif">
	      <input name="digital" type="checkbox" id="digital" value="Yes">
	      Yes, I have a digital camera and a computer with access to a high-speed internet connection.</font></td>
	  </tr>
	  <tr>
	    <td><div align="right"><font face="Verdana, Arial, Helvetica, sans-serif"><font size="1">Uploading and Reporting:</font></font></div></td>
	    <td><font size="2" face="Verdana, Arial, Helvetica, sans-serif">
	      <input name="digital2" type="checkbox" id="digital2" value="Yes">
	      Yes, I have experience uploading photos and online reporting.</font></td>
	  </tr>
	  <tr>
	    <td valign="top"><div align="right"><font face="Verdana, Arial, Helvetica, sans-serif"><font size="1">Describe the Cities or </font><font size="1">Area<br>
	      You Can Cover:</font></font></div></td>
	    <td><textarea name="phonefax" cols="50" rows="4" id="phonefax"></textarea></td>
	  </tr>
	  <tr valign="top">
	    <td><div align="right"><font face="Verdana, Arial, Helvetica, sans-serif"><font size="1">Other
            Comments:</font></font></div></td>
	    <td><textarea name="comments" cols="50" rows="4" id="comments"></textarea></td>
	  </tr>
	    <td><div align="center"><font face="Verdana, Arial, Helvetica, sans-serif"><font size="1">
	        <input type="reset" name="Submit2" value="Reset">
	      </font></font></div></td>
	    <td>
			<!-- <input name="Submit" type="submit" value="Submit"> -->
			<!-- <input name="Submit" type="submit" value="Submit" onClick="event.preventDefault(); grecaptcha.execute();"> -->
			<button class="g-recaptcha" 
				data-sitekey="6LfUWHkqAAAAAP5dbkBhqZ6CEe_tG_zZu2aluKhe" 
				data-callback='onSubmit' 
				data-action='submit'>Submit
			</button>
		</td>
	  </tr>
	  <tr>
	    <td><div align="right"><font face="Verdana, Arial, Helvetica, sans-serif" size="1"></font></div></td>
	    <td>&nbsp;</td>
	  </tr>
	  <tr>
	    <td colspan="2"><div align="right"><font face="Verdana, Arial, Helvetica, sans-serif" size="1"></font></div></td>
	  </tr>
	  </table>
</form>