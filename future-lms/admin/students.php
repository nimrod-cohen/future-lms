<div class="ui padded height grid stackable">
  <div class="ui form row">
    <div class="sixteen wide column">
      <div class="students-search-form">
        <div class="field">
          <div class="ui search selection dropdown courses">
            <div class="text"></div>
          </div>
        </div>
        <div class="field">
          <div class="ui search selection dropdown classes">
            <div class="text"></div>
          </div>
        </div>
        <div class="field">
          <input type="text" name="name_or_email" placeholder="Search name or email">
        </div>
        <div class="field">
          <div class="ui calendar" id="registration_month">
            <div class="ui input left icon">
              <i class="calendar icon"></i>
              <input type="text" placeholder="Date">
            </div>
          </div>
        </div>
        <div class="field">
          <button class="ui labeled icon blue button search-students">
            <i class="search icon"></i>
            Search
          </button>
        </div>
        <div class="field">
          <button class="ui labeled icon green disabled button add-student">
            <i class="dollar icon"></i>
            Add Student
          </button>
        </div>
      </div>
    </div>
  </div>
  <div class="row">
    <div class="sixteen wide column">
      <table class="ui very relaxed small celled table students">
        <thead>
          <th>ID</th>
          <th>Course</th>
          <th>Class</th>
          <th>Registration Date</th>
          <th>Email</th>
          <th>Name</th>
          <th>Phone</th>
          <th class="result-count">0 students</th>
        </thead>
        <tbody>
          <tr><td colspan="5">No results</td></tr>
        </tbody>
      </table>
    </div>
  </div>
</div>
<div class="ui modal" id="add-student-modal">
  <div class="header">Header</div>
  <div class="content step-1">
    <div class="ui grid">
      <div class="row">
        <div class="two wide column middle aligned">
          <div class="field">
            <div class="ui radio checkbox">
              <input id="radio-existing-student" type="radio" name="radio" checked="checked" data-target='add-existing-student'>
              <label for="radio-existing-student">Existing</label>
            </div>
          </div>
        </div>
        <div id="add-existing-student" class="fourteen wide column">
          <div class="field">
            <div class="ui search student">
              <div class="ui fluid icon input">
                <input class="prompt" type="text" placeholder="Search a student" />
                <i class="search icon"></i>
              </div>
            </div>
          </div>
        </div>
      </div>
      <div class="row">
        <div class="two wide column">
          <div class="ui radio checkbox">
            <input type="radio" name="radio" data-target='add-new-student' id="radio-new-student">
            <label for="radio-new-student">New</label>
          </div>
        </div>
        <div id='add-new-student' class="fourteen wide column">
          <div class="ui grid">
            <div class="row">
              <div class="eight wide column">
                <div class="field">
                  <div class="ui fluid left icon input disabled">
                    <input type="text" name="full-name" placeholder="Name">
                    <i class="user icon"></i>
                  </div>
                </div>
              </div>
              <div class="eight wide column">
                <div class="field">
                  <div class="ui fluid left icon input disabled">
                    <input type="text" name="phone" placeholder="Phone">
                    <i class="phone icon"></i>
                  </div>
                </div>
              </div>
            </div>
            <div class="row">
              <div class="eight wide column">
                <div class="field">
                  <div class="ui fluid left icon input disabled">
                    <input type="text" name="student-email" placeholder="Email">
                    <i class="at icon"></i>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
  <div class="content step-2 hidden">
    <div class="ui grid">
      <div class="row">
        <div class="two wide column">
          <label>Price</label>
        </div>
        <div class="four wide column">
          <div class="field">
            <div class="ui fluid left icon input">
              <input type="text" name="payment-sum" placeholder="Sum">
              <i class="dollar icon"></i>
            </div>
          </div>
        </div>
        <div class="ten wide column">
          <div class="field">
            <div class="ui fluid left input">
              <input type="text" name="invoice-to" id='invoice-to' placeholder="Invoice to (leave empty if same as name)">
            </div>
          </div>
        </div>
      </div>
      <div class="row">
        <div class="two wide column">
          <label>Payment method</label>
        </div>
        <div class="three wide column">
          <div class="ui form">
            <div class="grouped fields payment-method">
              <div class="field">
                <div class="ui radio checkbox">
                  <input type="radio" name="payment-method" checked="checked" value='credit' id='payment-method-credit'>
                  <label for='payment-method-credit'>Credit card</label>
                </div>
              </div>
              <div class="field">
                <div class="ui radio checkbox">
                  <input type="radio" name="payment-method" value='paypal' id='payment-method-paypal'>
                  <label for='payment-method-paypal'>Paypal</label>
                </div>
              </div>
              <div class="field">
                <div class="ui radio checkbox">
                  <input type="radio" name="payment-method" value='wire transfer' id='payment-method-wire'>
                  <label for='payment-method-wire'>Wire transfer</label>
                </div>
              </div>
              <div class="field">
                <div class="ui radio checkbox">
                  <input type="radio" name="payment-method" value='cheque' id='payment-method-cheque'>
                  <label for='payment-method-cheque'>Cashier Cheque</label>
                </div>
              </div>
              <div class="field">
                <div class="ui radio checkbox">
                  <input type="radio" name="payment-method" value='cash' id='payment-method-cash'>
                  <label for='payment-method-cash'>Cash</label>
                </div>
              </div>
            </div>
          </div>
        </div>
        <div class="eleven wide column payment-details hidden">
          <div class='ui form'>
            <div class="ui left corner labeled input" style='width:100%'>
              <div class="ui left corner label">
                  <i class="asterisk icon"></i>
              </div>
              <textarea id='payment-comment' style='resize:none; width:100%; height:90px; min-height:90px; margin-bottom:6px;'></textarea>
            </div>
            <div class="field">
              <div class="ui fluid left icon input">
                <input type="text" id="transaction-ref" placeholder="Transaction Reference">
                <i class="user icon"></i>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
  <div class="content step-3 hidden">
  </div>
  <div class="actions">
    <div class="ui primary prev button disabled">Back</div>
    <div class="ui primary next button">Next</div>
    <div class="ui cancel button">Cancel</div>
  </div>
</div>

